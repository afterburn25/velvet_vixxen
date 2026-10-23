<?php
declare(strict_types=1);
if (!defined('ROOT_PATH')) { define('ROOT_PATH', dirname(__DIR__, 3)); }require ROOT_PATH.'/app/bootstrap.php';if(!is_installed())redirect(base_url('install/'));
$member=require_member();$memberId=(string)$member['id'];$error='';
if($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if(!verify_csrf())throw new RuntimeException('Your session expired.');
        $action=$_POST['action']??null;$id=$_POST['subscription_id']??null;
        if(!is_string($id)||!in_array($action,['cancel_subscription','resume_subscription'],true))throw new RuntimeException('Invalid billing action.');
        $agreement=app_store()->recurringAgreementForSubscription($id);
        if($agreement!==null) {
            $revision=$_POST['agreement_revision']??null;$requestId=$_POST['request_id']??null;
            if(!is_string($revision)||!ctype_digit($revision)||!is_string($requestId))throw new RuntimeException('Reload the current billing form.');
            $authorize=static function()use($memberId):void {
                $current=current_member();if(!$current||(string)$current['id']!==$memberId)throw new RuntimeException('Sign in again before changing billing.',403);
            };
            commerce_service()->requestRecurringCommand($memberId,$id,$action==='cancel_subscription'?'cancel':'resume',(int)$revision,$requestId,$authorize);
            flash('billing_success','Your request was processed. Review the confirmed renewal state below.');
        } else {
            $legacy=null;foreach(app_store()->subscriptions($memberId)as$row)if((string)$row['id']===$id)$legacy=$row;
            if(!$legacy||($legacy['providerKey']??'')!=='sandbox')throw new RuntimeException('Legacy provider renewal requires owner review.');
            if($action==='cancel_subscription')commerce_service()->cancelSubscription($memberId,$id,true);else commerce_service()->resumeSubscription($memberId,$id);
            flash('billing_success','Sandbox compatibility renewal updated.');
        }
        redirect(base_url('account/billing.php'));
    }catch(Throwable $e){$error=$e->getMessage();}
}
$recurringMemberships=commerce_service()->recurringMemberships($memberId);
$subscriptions=app_store()->subscriptions($memberId);$orders=app_store()->orders($memberId,null,100);$pageTitle='Billing & Purchases';$activeNav='membership';require root_path('app/Views/public/partials/header.php');$success=flash('billing_success');
?>
<div class="member-main"><section class="member-dashboard"><div class="member-page-head"><div><span class="eyebrow pink">Member billing</span><h1>Subscriptions & purchases</h1><p>Manage renewals and review receipts for subscriptions, PPV releases, rentals, and bundles.</p></div><a class="button button--ghost" href="<?=e(base_url('account/'))?>">Back to Account</a></div><?php if($success):?><div class="member-alert success"><?=e($success)?></div><?php endif;?><?php if($error):?><div class="member-alert error"><?=e($error)?></div><?php endif;?><section class="member-panel wide"><div class="member-panel-head"><div><span class="eyebrow pink">Memberships</span><h2>Subscription status</h2></div><a class="button button--primary button--small" href="<?=e(base_url('membership/'))?>">View Plans</a></div><?php if($subscriptions===[]):?><div class="member-empty">No subscription history yet.</div><?php else:?><div class="billing-list"><?php foreach($subscriptions as $sub):?><article><div><span class="member-status-pill <?=in_array((string)$sub['status'],['active','trialing'],true)?'active':''?>"><?=e((string)$sub['status'])?></span><h3><?=e((string)($sub['planName']??'Membership'))?></h3><small>Period ends <?=e(date('F j, Y',strtotime((string)($sub['currentPeriodEnd']??'now'))))?></small><?php require root_path('app/Views/public/partials/purchased-membership.php'); ?><?php require root_path('app/Views/public/partials/recurring-membership.php'); ?><?php if(!isset($recurringMemberships[(string)$sub['id']]) && !empty($sub['cancelAtPeriodEnd'])):?><small class="billing-warning">Cancellation scheduled at period end.</small><?php endif;?></div><?php $recurring=$recurringMemberships[(string)$sub['id']]??null; if($recurring!==null ? ($recurring['canCancel']||$recurring['canResume']) : (in_array((string)$sub['status'],['active','trialing'],true)&&($sub['providerKey']??'')==='sandbox')):?><form method="post"><?=csrf_field()?><input type="hidden" name="subscription_id" value="<?=e((string)$sub['id'])?>"><?php if($recurring!==null):?><input type="hidden" name="agreement_revision" value="<?=e((string)$recurring['revision'])?>"><input type="hidden" name="request_id" value="<?=e('member:'.bin2hex(random_bytes(16)))?>"><?php endif;?><?php if($recurring!==null?$recurring['canResume']:!empty($sub['cancelAtPeriodEnd'])):?><input type="hidden" name="action" value="resume_subscription"><button class="button button--ghost button--small">Resume Renewal</button><?php else:?><input type="hidden" name="action" value="cancel_subscription"><button class="button button--ghost button--small">Cancel at Period End</button><?php endif;?></form><?php endif;?></article><?php endforeach;?></div><?php endif;?></section><section class="member-panel wide"><div class="member-panel-head"><div><span class="eyebrow pink">History</span><h2>Orders & receipts</h2></div></div><?php if($orders===[]):?><div class="member-empty">No purchases yet.</div><?php else:?><div class="order-history"><div class="order-history-head"><span>Order</span><span>Date</span><span>Status</span><span>Total</span><span></span></div><?php foreach($orders as $order):?><div class="order-history-row"><strong><?=e((string)$order['orderNumber'])?><small><?=e(implode(', ',array_map(static fn(array $i):string=>(string)$i['title'],(array)$order['items'])))?></small></strong><span><?=e(date('M j, Y',strtotime((string)$order['createdAt'])))?></span><span class="order-status <?=e((string)$order['status'])?>"><?=e((string)$order['status'])?></span><span><?=e(money((float)$order['grandTotal'],(string)$order['currency']))?></span><a href="<?=e(base_url('receipt/?order='.rawurlencode((string)$order['orderNumber'])))?>">Receipt</a></div><?php endforeach;?></div><?php endif;?></section></section></div>
<?php require root_path('app/Views/public/partials/footer.php'); ?>
