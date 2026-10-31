# Advanced Users

WordPress uses Gravatar avatars, but author biographies and other profile details still need to be maintained separately. This plugin brings those details into WordPress — useful for editorial sites with author bios, bylines and contributor profiles.

## Sync

- Import biographies, first and last names from Gravatar.
- Store additional profile data: location, job title, company and verified social accounts.
- Fill empty fields automatically when a WordPress user is registered or their profile is updated.
- Run a manual sync for one user or process users in small, resumable batches.
- Preserve existing values by default; force sync overwrites them with non-empty remote values.
- Optionally download Gravatar images and use local copies through WordPress's avatar API.

Biographies use WordPress's standard `description` field. Names use `first_name` and `last_name`. Additional fields use the `advanced_users_gravatar_` user-meta prefix, for example `advanced_users_gravatar_job_title` and `advanced_users_gravatar_verified_accounts`.

## Setup

Requires WordPress 6.8+ and PHP 8.3+.

1. Copy `wp-advanced-users` into `wp-content/plugins` and activate it.
2. Set your Gravatar key in `wp-config.php`:

```php
define('ADVANCED_USERS_GRAVATAR_KEY', 'your-api-key');
```

3. Use **Utenti → Sync Gravatar**, or the sync buttons on a user profile.

## Resumable sync and cache

Bulk sync saves its cursor after each user. Closing the page pauses processing; return to **Utenti → Sync Gravatar** and choose **Riprendi**. The snapshot includes users belonging to the current site when the job starts. Newly registered users are handled by their registration hook and are excluded from that bulk job. Deleting users during a sync does not shift the cursor. **Annulla** closes the current job so another can start.

Rate limits and temporary API failures pause the whole job without skipping the affected user. Resume after the retry time shown in the dashboard. Requests are serialized using a database lock, released when the request ends. No cron or CLI worker is required; the page must stay open while batches run.

Successful API responses are cached for six hours; missing profiles for one day. Cached responses still fill empty local fields. Force sync refreshes the profile cache, including missing profiles, while respecting a server retry delay. The `advanced_users_profile_cache_ttl` and `advanced_users_missing_cache_ttl` filters change these lifetimes, in seconds. The dashboard keeps the latest 100 user results for the current job; total progress is stored separately.

## Optional local avatars

Local avatars are disabled by default. An administrator can enable **Avatar locali** on the sync page, then sync users to populate their images. Downloads happen during sync, never while rendering a public page.

The override uses [WordPress's avatar API](https://developer.wordpress.org/reference/functions/get_avatar_data/), so normal `get_avatar()` and `get_avatar_url()` calls use the local image. It respects `force_default` and falls back to WordPress when no local image is available. Disable the setting to restore standard avatar behavior immediately.

Images are rechecked after six hours, with conditional HTTP requests where supported. Identical image bytes reuse the existing attachment; force sync checks again immediately. A changed image at the same URL is imported correctly. Failed refreshes keep the existing image and report a warning in the bulk log. Superseded images remain in the media library to preserve any other references to them; remove them manually when no longer needed. WordPress creates the usual image sizes and retains the source format.

## Limits

Sync runs from Gravatar to WordPress. It does not push local changes back or poll for remote updates. Fields unavailable in a remote profile do not erase existing local values.

The plugin provides profile data; your theme controls how author information appears. It includes no editorial roles, team page or author redirects. Verified accounts can contain an `is_hidden` flag: respect it when rendering them publicly.

[Gravatar profile fields](https://docs.gravatar.com/rest/api-data-specifications/data-models/)
