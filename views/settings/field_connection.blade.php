<div>
    @empty($formModel->connection_data['status'])
        <a
            class="btn btn-success"
            href="<?= admin_url('igniterlabs/squarepos/merchant/authorize') ?>"
        ><i class="fa fa-link"></i>&nbsp;&nbsp;<?= lang('igniterlabs.squarepos::default.button_connect') ?></a>
    @else
        <a
            class="btn btn-danger"
            href="<?= admin_url('igniterlabs/squarepos/merchant/revoke') ?>"
        ><i class="fa fa-unlink"></i>&nbsp;&nbsp;<?= lang('igniterlabs.squarepos::default.button_disconnect') ?></a>
    @endempty
</div>
<input type="hidden" name="{{ $field->getName() }}" value="{{ $field->value }}">
