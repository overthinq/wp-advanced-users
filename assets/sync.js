( () => {
	const { nonce, i18n } = window.advancedUsersSync;
	let job = window.advancedUsersSync.job;
	let busy = false;
	let error = '';

	const start = document.getElementById( 'advanced-users-start' );
	const resume = document.getElementById( 'advanced-users-resume' );
	const cancel = document.getElementById( 'advanced-users-cancel' );
	const progress = document.getElementById( 'advanced-users-progress' );

	const RUNNING = [ 'ready', 'running' ];
	const isOpen = () => job.id && ! [ 'completed', 'cancelled' ].includes( job.status );
	const isWaiting = () => job.retry_at * 1000 > Date.now();
	const summary = () => i18n.progress
		.replace( '%1$s', i18n.statuses[ job.status ] ?? job.status )
		.replace( '%2$d', job.processed )
		.replace( '%3$d', job.total );

	function render() {
		start.disabled = busy || isOpen();
		resume.disabled = busy || ! isOpen() || isWaiting();
		cancel.disabled = busy || ! isOpen();

		const parts = [ job.id ? summary() : i18n.idle ];
		if ( job.message ) {
			parts.push( job.message );
		}
		if ( isWaiting() ) {
			parts.push( i18n.retry.replace( '%s', new Date( job.retry_at * 1000 ).toLocaleTimeString() ) );
		}
		if ( error ) {
			parts.push( error );
		}
		progress.textContent = parts.join( ' ' );
	}

	async function send( operation ) {
		const response = await fetch( window.ajaxurl, {
			method: 'POST',
			body: new URLSearchParams( { action: 'advanced_users_sync_batch', operation, job_id: job.id ?? '', _ajax_nonce: nonce } ),
		} );
		const result = await response.json();
		if ( ! result.success ) {
			throw new Error( typeof result.data === 'string' ? result.data : i18n.failed );
		}
		job = result.data;
		render();
	}

	// Un passo alla volta finché il job è attivo; a fine corsa si ricarica per mostrare il log.
	async function run( operation ) {
		busy = true;
		error = '';
		render();
		try {
			await send( 'resume' === operation ? 'tick' : operation );
			while ( 'cancel' !== operation && RUNNING.includes( job.status ) ) {
				await send( 'tick' );
				if ( RUNNING.includes( job.status ) ) {
					await new Promise( ( resolve ) => setTimeout( resolve, 1000 ) );
				}
			}
			window.location.reload();
		} catch ( exception ) {
			busy = false;
			error = exception.message;
			render();
		}
	}

	start.addEventListener( 'click', () => run( 'start' ) );
	resume.addEventListener( 'click', () => run( 'resume' ) );
	cancel.addEventListener( 'click', () => run( 'cancel' ) );
	setInterval( () => {
		if ( ! busy ) {
			render();
		}
	}, 1000 );
	render();
} )();
