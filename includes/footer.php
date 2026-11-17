<?php
$currentScript = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$isAdminArea = str_contains($currentScript, '/admin/');
$basePath = $isAdminArea ? '../' : '';
?>

</main>

<footer class="site-footer">
    <div class="container">
        <div class="site-footer__inner">
            <div>
                <strong>CARREPAIR</strong>
                <p>Автоматизированная система записи, обслуживания и контроля заявок автомастерской.</p>
            </div>

            <div>
                <span>Йошкар-Ола</span>
                <span>2026</span>
            </div>
        </div>
    </div>
</footer>

<script src="<?= $basePath ?>assets/js/script.js"></script>
</body>
</html>