<?php
// Navbar-Profilbild Partial – in allen Seiten eingebunden
// Benötigt: $profilbild (aus db_config.php), $benutzer_name
?>
<style>
.nav-dot {
    position: absolute;
    top: 2px;
    right: 2px;
    width: 9px;
    height: 9px;
    background: #ffcc00;
    border-radius: 50%;
    box-shadow: 0 0 6px #ffcc00;
    animation: pulse-dot 1.5s infinite;
    pointer-events: none;
}
.nav-dot-navbar {
    position: absolute;
    top: -6px;
    right: -6px;
    width: 9px;
    height: 9px;
    background: #ffcc00;
    border-radius: 50%;
    box-shadow: 0 0 6px #ffcc00;
    animation: pulse-dot 1.5s infinite;
    pointer-events: none;
}
@keyframes pulse-dot { 0%,100%{box-shadow:0 0 4px #ffcc00;} 50%{box-shadow:0 0 11px #ffcc00;} }
</style>
<script>
(function() {
    fetch('get_pending_resets.php')
        .then(r => r.json())
        .then(data => {
            if (!data.count || data.count < 1) return;

            const navLinks = document.querySelector('.nav-links');
            if (navLinks) {
                const adminLink = navLinks.querySelector('a[href="admin_dashboard.php"]');
                if (adminLink && !adminLink.querySelector('.nav-dot-navbar')) {
                    adminLink.style.position = 'relative';
                    adminLink.insertAdjacentHTML('beforeend', '<span class="nav-dot-navbar"></span>');
                }
            }

            document.querySelectorAll('a[href="admin_sicherheit.php"]').forEach(el => {
                if (!el.querySelector('.nav-dot')) {
                    el.style.position = 'relative';
                    el.insertAdjacentHTML('beforeend', '<span class="nav-dot"></span>');
                }
            });
        })
        .catch(() => {});
})();
</script>
<script>
(function scheduleReload() {
    setTimeout(function() {
        if (document.querySelector('.modal-overlay.open')) {
            scheduleReload();
        } else {
            location.reload();
        }
    }, 60000);
})();
</script>
<script>
(function() {
    fetch('get_pending_absences.php')
        .then(r => r.json())
        .then(data => {
            if (!data.count || data.count < 1) return;

            // Gelber Punkt auf "Admin" in der Hauptnavbar
            const navLinks = document.querySelector('.nav-links');
            if (navLinks) {
                const adminLink = navLinks.querySelector('a[href="admin_dashboard.php"]');
                if (adminLink && !adminLink.querySelector('.nav-dot-navbar')) {
                    adminLink.style.position = 'relative';
                    adminLink.insertAdjacentHTML('beforeend', '<span class="nav-dot-navbar"></span>');
                }
            }

            // Gelber Punkt auf "Abwesenheiten" in der Subnav
            document.querySelectorAll('a[href="admin_abwesenheiten.php"]').forEach(el => {
                if (!el.closest('.nav-links') && !el.querySelector('.nav-dot')) {
                    el.style.position = 'relative';
                    el.insertAdjacentHTML('beforeend', '<span class="nav-dot"></span>');
                }
            });
        })
        .catch(() => {});
})();
</script>
<script>
(function() {
    // Dropdown bleibt offen wenn Maus nach unten bewegt wird
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.msg-container').forEach(function(el) {
            var closeTimer;
            el.addEventListener('mouseenter', function() {
                clearTimeout(closeTimer);
                el.classList.add('msg-open');
            });
            el.addEventListener('mouseleave', function() {
                closeTimer = setTimeout(function() { el.classList.remove('msg-open'); }, 200);
            });

            <?php if (isset($_SESSION['rolle']) && in_array($_SESSION['rolle'], ['Admin', 'Manager'])): ?>
            // Admin/Manager: Klick auf Briefumschlag-Icon öffnet Benachrichtigungsseite
            el.style.cursor = 'pointer';
            el.addEventListener('click', function(e) {
                if (!e.target.closest('.bell-dropdown')) {
                    window.location.href = 'admin_benachrichtigungen.php';
                }
            });
            <?php endif; ?>
        });
    });

    <?php if (isset($_SESSION['rolle']) && in_array($_SESSION['rolle'], ['Admin', 'Manager'])): ?>
    // Admin/Manager: Klick auf Glocken-Icon öffnet Wartungsseite
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.bell-container:not(.msg-container)').forEach(function(el) {
            el.style.cursor = 'pointer';
            el.addEventListener('click', function(e) {
                if (!e.target.closest('.bell-dropdown')) {
                    window.location.href = 'admin_wartung.php';
                }
            });
        });
    });
    <?php endif; ?>

    // Live-Löschen per Mülleimer-Button
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.msg-del-btn');
        if (!btn) return;
        e.stopPropagation();
        var item = btn.closest('.bell-item[data-id]');
        if (!item) return;
        fetch('delete_nachricht_user.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'id=' + encodeURIComponent(item.dataset.id)
        }).catch(function() {});
        item.style.transition = 'max-height 0.25s ease, opacity 0.2s ease, padding 0.2s ease';
        item.style.overflow = 'hidden';
        item.style.maxHeight = item.offsetHeight + 'px';
        void item.offsetHeight;
        item.style.maxHeight = '0';
        item.style.opacity = '0';
        setTimeout(function() { item.remove(); }, 280);
    });
})();
</script>
<a href="einstellungen.php" class="user-profile-link">
    <?php if (!empty($profilbild) && file_exists("uploads/profilbilder/" . $profilbild)): ?>
        <img src="uploads/profilbilder/<?php echo htmlspecialchars($profilbild); ?>" class="user-avatar" alt="Profilbild">
    <?php else: ?>
        <i class="fas fa-user-circle" style="font-size:1.5rem;color:#aaa;"></i>
    <?php endif; ?>
    <span class="username"><?php echo htmlspecialchars($benutzer_name); ?></span>
</a>
