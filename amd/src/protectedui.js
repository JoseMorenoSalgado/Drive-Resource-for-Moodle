/**
 * Lightweight client-side deterrents for protected image/file resources.
 *
 * These controls do not provide DRM. Authorization is enforced server-side by
 * protected.php; this module only removes accidental browser actions.
 *
 * @module     mod_videoplayer/protectedui
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {
    var block = function(event) {
        event.preventDefault();
        event.stopPropagation();
        return false;
    };

    var initRoot = function(root) {
        if (root.dataset.protectedUiReady === '1' || root.dataset.disableContextMenu !== '1') {
            return;
        }
        root.dataset.protectedUiReady = '1';
        ['contextmenu', 'dragstart'].forEach(function(name) {
            root.addEventListener(name, block, true);
        });
        root.addEventListener('keydown', function(event) {
            var key = (event.key || '').toLowerCase();
            if ((event.ctrlKey || event.metaKey) && ['s', 'p'].indexOf(key) !== -1) {
                block(event);
            }
        }, true);
    };

    var init = function() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-protected-ui]'), initRoot);
    };

    return {init: init};
});
