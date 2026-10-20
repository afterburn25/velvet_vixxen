<?php
declare(strict_types=1);
require dirname(__DIR__) . '/TestEnvironment.php';

$env = velvet_test_environment('communications');
$failures = [];
$assert = static function (bool $ok, string $message) use (&$failures): void { if (!$ok) $failures[] = $message; };
try {
    $store = $env['store'];
    $first = $env['auth']->register('First Member', 'first@example.test', 'MemberFixture12!')['member'];
    $second = $env['auth']->register('Second Member', 'second@example.test', 'MemberFixture12!')['member'];
    $id = (string) $first['id'];
    $thread = $store->createCommunicationThread(['memberId' => $id, 'subject' => 'Fixture conversation']);
    $store->sendCommunicationMessage(['threadId' => $thread['id'], 'senderType' => 'member', 'senderMemberId' => $id, 'body' => 'Member question']);
    $assert($store->communicationThread($thread['id'])['adminUnreadCount'] === 1, 'Member message did not update owner unread count.');
    $store->sendCommunicationMessage(['threadId' => $thread['id'], 'senderType' => 'admin', 'body' => 'Private internal fixture', 'visibility' => 'internal']);
    $assert(count($store->communicationMessages($thread['id'], false)) === 1, 'Internal message was exposed through public-message filtering.');
    $assert($store->memberNotifications($id) === [], 'An internal note generated a member notification.');
    $store->sendCommunicationMessage(['threadId' => $thread['id'], 'senderType' => 'admin', 'body' => 'Owner reply fixture']);
    $assert($store->communicationThread($thread['id'])['memberUnreadCount'] === 1, 'Owner reply did not update member unread count.');
    $notifications = $store->memberNotifications($id, true);
    $assert(count($notifications) === 1, 'Owner reply notification was missing.');
    $assert(!str_contains(json_encode($notifications), 'Owner reply fixture'), 'Notification exposed a private message body.');
    $store->markMemberNotificationRead($notifications[0]['id'], $second['id']);
    $assert(count($store->memberNotifications($id, true)) === 1, 'Another member marked a notification read.');
    $store->markMemberNotificationRead($notifications[0]['id'], $id);
    $assert($store->memberNotifications($id, true) === [], 'Owning member could not mark notification read.');
    $assert($store->communicationThreads(['memberId' => $second['id']]) === [], 'Member conversation filter returned another member record.');
    $block = $store->blockCommunication(['memberId' => $id, 'scope' => 'messaging']);
    try { $store->sendCommunicationMessage(['threadId' => $thread['id'], 'senderType' => 'member', 'body' => 'Blocked message']); $failures[] = 'Communication block allowed a new message.'; } catch (RuntimeException) {}
    $store->removeCommunicationBlock($block['id']);
    $request = $store->createContentRequest(['memberId' => $id, 'title' => 'Fixture content request', 'description' => 'Example request']);
    $assert($request['status'] === 'submitted' && $request['threadId'] !== null, 'Content request did not create its conversation.');
    $assert($store->contentRequests(['memberId' => $second['id']]) === [], 'Member request filtering exposed another member record.');
    $booking = $store->createBookingRequest(['contactName' => 'Fixture Guest', 'contactEmail' => ' Guest@Example.Test ', 'details' => 'Example appearance inquiry']);
    $assert($booking['contactEmail'] === 'guest@example.test' && $booking['status'] === 'submitted', 'Guest booking was not normalized or persisted.');
    try { $store->createBookingRequest(['contactName' => 'Guest', 'contactEmail' => 'invalid-address', 'details' => 'Example']); $failures[] = 'Invalid booking email was accepted.'; } catch (RuntimeException) {}
} catch (Throwable $e) { $failures[] = $e->getMessage(); }
finally { velvet_test_cleanup($env); }
velvet_finish_test('Communications, request and booking regressions', $failures);
