<?php
declare(strict_types=1);
$detail=$paymentRecoveryRequest['agreement']!==null;
?>
<div class="payment-recovery" data-recurring-recovery>
<section class="admin-card"><span class="admin-kicker">Commerce · Payment recovery</span><h2>Recurring memberships</h2>
<p>Review confirmed renewal settings and outstanding requests. Checking an outcome queries the original request; it never submits another charge, cancellation or resumption.</p>
<a href="<?=e(vvx_payment_recovery_url())?>">Payment and refund records</a></section>
<?php if($paymentRecoveryReadFailed):?><section class="admin-card"><h2>Recurring records unavailable</h2><p>Resolve the reported problem and reload. An unavailable record is not an empty billing history.</p></section></div><?php return;endif;?>
<?php if($paymentRecoveryPage['confirmation']):?><section class="admin-card"><h2>Confirm your identity before taking action</h2><a href="<?=e(base_url('admin/?page=security'))?>">Confirm in Security</a></section><?php endif;?>
<?php if($detail):?><a class="admin-button" href="<?=e(vvx_payment_recovery_url([],['view'=>'recurring']))?>">Back to recurring memberships</a><?php endif;?>
<?php if($paymentRecoveryPage['rows']===[]):?><section class="admin-card"><h2>No recurring records on this page</h2><p>Historical subscriptions remain legacy until an independently authorized agreement exists.</p></section><?php endif;?>
<?php foreach($paymentRecoveryPage['rows'] as $agreement):?>
<article class="admin-card payment-recovery-record" data-recurring-agreement="<?=e($agreement['id'])?>">
<h2>Membership <?=e($agreement['subscriptionId'])?></h2><p>Confirmed state: <?=e($agreement['state'])?>. <?= $agreement['cancelAtPeriodEnd']?'Renewal cancellation confirmed.':'Renewal cancellation has not been confirmed.'?></p>
<dl class="payment-recovery-facts"><div><dt>Paid through (UTC)</dt><dd><?=e(gmdate('Y-m-d H:i:s',$agreement['periodEnd']))?></dd></div><div><dt>Agreement revision</dt><dd><?=e((string)$agreement['revision'])?></dd></div>
<?php if($agreement['amountMinor']!==null):?><div><dt>Purchased renewal amount</dt><dd><?=e(money($agreement['amountMinor']/100,$agreement['currency']))?> every <?=e((string)$agreement['intervalCount'].' '.$agreement['interval'])?></dd></div><?php endif;?></dl>
<?php if($agreement['erased']):?><p>Account closed. Billing reconciliation does not restore access or erased terms.</p><?php endif;?>
<div class="payment-recovery-links"><a href="<?=e(base_url('admin/?page=orders&order='.rawurlencode($agreement['orderId'])))?>">Original order</a>
<?php if(!$detail):?><a class="admin-button" href="<?=e(vvx_payment_recovery_url([],['view'=>'recurring','agreement'=>$agreement['id']]))?>">Review membership requests</a><?php endif;?></div>
<?php if($detail):?>
<?php foreach($agreement['requests'] as $record):?><section class="payment-recovery-action" data-recurring-record="<?=e($record['id'])?>"><h3><?=e(ucfirst($record['action']))?> · <?=e($record['state'])?></h3>
<p>Saved <?=e(gmdate('Y-m-d H:i:s',$record['createdAt']))?> UTC. <?= $record['issue']!==null?'This outcome needs review.':''?></p>
<?php if($record['canReconcile'] && !$paymentRecoveryPage['confirmation']):?><form method="post" action="<?=e(vvx_payment_recovery_url($paymentRecoveryRequest))?>"><?=csrf_field()?>
<input type="hidden" name="action" value="payment_recovery_recurring_check"><input type="hidden" name="recurring_record" value="<?=e($record['id'])?>"><input type="hidden" name="agreement_revision" value="<?=e((string)$agreement['revision'])?>"><button class="admin-button primary">Check original recurring outcome</button></form><?php endif;?>
</section><?php endforeach;?>
<?php if($agreement['nextRequestCursor']!==null):?><a class="admin-button" href="<?=e(vvx_payment_recovery_url($paymentRecoveryRequest,['journalAfter'=>$agreement['nextRequestCursor']]))?>">Next request page</a><?php endif;?>
<?php endif;?></article><?php endforeach;?>
<?php if(!$detail && $paymentRecoveryPage['nextCursor']!==null):?><a class="admin-button primary" href="<?=e(vvx_payment_recovery_url($paymentRecoveryRequest,['after'=>$paymentRecoveryPage['nextCursor']]))?>">Next membership page</a><?php endif;?>
</div>
