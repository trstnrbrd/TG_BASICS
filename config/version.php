<?php
/**
 * config/version.php
 * The system's release number, shown at the bottom of the sidebar, on the landing page footer and in
 * Settings > My Account > About This System. Release history: CHANGELOG.md.
 *
 * Semantic Versioning (MAJOR.MINOR.PATCH):
 *   MAJOR - a change that breaks what came before (database schema rework, a module rebuilt)
 *   MINOR - a new feature that keeps everything else working
 *   PATCH - a bug fix only
 *
 * Releasing: update both constants, add the section to CHANGELOG.md, commit, then tag the commit
 * (git tag -a v2.2.0 -m "...") and push the tag.
 */
if (!defined('APP_VERSION')) {
    define('APP_VERSION', '2.3.0');
    define('APP_RELEASE_DATE', '2026-11-04');
}
