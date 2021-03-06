<?php
use Destiny\Common\Utils\Tpl;
?>
<div id="alerts-static">
    <?php if(!empty($this->error)): ?>
    <div class="alert alert-danger">
        <strong><i class="fa fa-exclamation-triangle"></i> Error</strong>
        <?=Tpl::out($this->error)?>
    </div>
    <?php endif ?>
    <?php if(!empty($this->success)): ?>
    <div class="alert alert-info">
        <strong><i class="fa fa-check-square-o"></i> Success</strong>
        <?=Tpl::out($this->success)?>
    </div>
    <?php endif ?>
</div>