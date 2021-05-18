<div>
    <?php if (!empty($formModel->connection_data['merchant_id'])) { ?>
        <a
            class="btn btn-danger"
            href="<?= admin_url('igniterlabs/squarepos/merchant/revoke') ?>"
        ><i class="fa fa-unlink"></i>&nbsp;&nbsp;<?= lang('igniterlabs.squarepos::default.button_disconnect') ?></a>
    <?php } else { ?>
        <a
            class="btn btn-success"
            href="<?= admin_url('igniterlabs/squarepos/merchant/authorize') ?>"
        ><i class="fa fa-link"></i>&nbsp;&nbsp;<?= lang('igniterlabs.squarepos::default.button_connect') ?></a>
    <?php } ?>
</div>