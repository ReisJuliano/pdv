    </main>
</div>

<div id="toastContainer" class="toast-container"></div>
<div id="modalBackdrop" class="modal-backdrop" onclick="closeAllModals()"></div>

<script src="<?= url('assets/js/main.js') ?>"></script>
<?php if (isset($extraJs)): ?>
<script src="<?= $extraJs ?>"></script>
<?php endif; ?>
<?php if (isset($inlineJs)): ?>
<script><?= $inlineJs ?></script>
<?php endif; ?>
</body>
</html>
