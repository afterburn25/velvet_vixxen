<?php
declare(strict_types=1);
$recurring=$recurringMemberships[(string)$sub['id']]??null;
if($recurring===null) return;
$request=$recurring['pendingRequest'];
?>
<div class="purchased-terms" data-recurring-state="<?=e($request['state']??$recurring['state'])?>">
<?php if($request!==null):?><small class="billing-warning"><?=e(match($request['action']){
    'cancel'=>'Cancellation requested; awaiting confirmation.',
    'resume'=>'Resumption requested; awaiting confirmation.',
    'cycle'=>'Renewal outcome awaiting confirmation. Your paid-through record has not changed.',
    default=>'Recurring activation awaiting confirmation.',
})?></small>
<?php elseif($recurring['cancelAtPeriodEnd']):?><small>Renewal canceled. Paid through <?=e(gmdate('F j, Y H:i',$recurring['periodEnd']))?> UTC.</small>
<?php elseif($recurring['state']==='active'):?><small>Automatic renewal confirmed. Paid through <?=e(gmdate('F j, Y H:i',$recurring['periodEnd']))?> UTC.</small>
<?php else:?><small>Recurring billing needs review. Contact the site owner.</small><?php endif;?>
</div>
