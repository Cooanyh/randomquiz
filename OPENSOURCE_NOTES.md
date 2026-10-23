# Open source release notes

This working copy is prepared as a self-hosted GPL-3 release. It has not been deployed or pushed.

## Removed administration surface

- Deleted `admin.html` and removed its authentication, management session, and frontend calls.
- Removed all management-only `admin_*` actions from `api.php`:

  `admin_logout`, `admin_login`, `admin_get_stats`, `admin_reset_calls`, `admin_set_unlimited`, `admin_set_note`, `admin_delete_account`, `admin_ban_ip`, `admin_set_custom_limit`, `admin_export_data`, `admin_cleanup_old`, `admin_get_settings`, `admin_update_settings`, `admin_delete_quiz`, `admin_list_quizzes`, `admin_get_usage_trends`, `admin_batch_action`, `admin_set_user_level`, `admin_preview_quiz`, `admin_rename_quiz`, `admin_change_password`, `admin_create_backup`, `admin_restore_backup`, `admin_get_model_config`, `admin_update_model_config`, `admin_get_peak_hours`, `admin_get_error_logs`, `admin_clear_error_logs`, `admin_get_tips`, `admin_save_tips`, `admin_get_pending_quizzes`, `admin_review_quiz`, `admin_set_quiz_visibility`, `admin_set_quiz_access_mode`, and `admin_get_audit_logs`.

- Removed the shared administrator password and privileged-account branches, including the hard-coded superuser identity. User login sessions and owner-scoped quiz operations remain.
- Also removed the legacy password-protected `upload_quiz`, `delete_quiz`, `rename_quiz`, `save_quiz_announcement`, and `get_all_quiz_announcements` management actions, which predated the `admin_*` naming convention.
- User uploads are private to their account. Optional AI review is controlled by `ENABLE_QUIZ_AI_REVIEW`; failed reviews do not create a manual-review queue because there is no management interface.
- Removed administration-only database helpers and per-user unlimited, ban, manual-level, and custom-limit overrides. AI request limits are configured in `config.php`.

## Privacy and portability

- Added `config.example.php`; the real `config.php`, `data/`, `vendor/`, and logs are ignored by Git.
- Replaced production API, storage, email, and author references with relative paths or generic placeholders. PWA manifest, icons, and service-worker registration are relative to the app location.
- Removed model price estimates and account-upgrade prompts. Token usage counts remain available without monetary estimates.
- Deleted `doc/progress.md`, which contained production paths, deployment history, and infrastructure details.
- Added installation and security guidance in [README.md](README.md). The GPL-3 license is retained.

## User-facing API retained

The API keeps account registration/login/logout, user settings, AI assistance, local/user/public quiz reads, owner-scoped upload/edit/rename/delete, quiz quotas, public announcements and tips, user data sync, notifications, and quiz sharing.
