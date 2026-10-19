<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

trait CommunicationStoreTrait
{
    /** @return array<string,mixed> */
    public function communicationSettings(): array
    {
        $defaults = [
            'messagingEnabled' => true,
            'contentRequestsEnabled' => true,
            'bookingEnabled' => true,
            'memberAttachmentsEnabled' => true,
            'publicBookingAttachmentsEnabled' => true,
            'maxAttachmentMb' => 10,
            'allowedExtensions' => ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'txt'],
            'autoAcknowledgeBooking' => true,
            'autoAcknowledgeRequests' => true,
            'supportReplyName' => 'Nikki / Staff',
            'bookingResponseDays' => 3,
        ];
        return array_replace($defaults, (array) $this->setting('communications', []));
    }

    /** @param array<string,mixed> $settings */
    public function saveCommunicationSettings(array $settings): array
    {
        $current = $this->communicationSettings();
        $allowed = array_values(array_unique(array_filter(array_map(
            static fn(string $value): string => strtolower(trim($value)),
            (array) ($settings['allowedExtensions'] ?? $current['allowedExtensions'])
        ), static fn(string $value): bool => preg_match('/^[a-z0-9]{1,10}$/', $value) === 1)));
        $saved = [
            'messagingEnabled' => (bool) ($settings['messagingEnabled'] ?? false),
            'contentRequestsEnabled' => (bool) ($settings['contentRequestsEnabled'] ?? false),
            'bookingEnabled' => (bool) ($settings['bookingEnabled'] ?? false),
            'memberAttachmentsEnabled' => (bool) ($settings['memberAttachmentsEnabled'] ?? false),
            'publicBookingAttachmentsEnabled' => (bool) ($settings['publicBookingAttachmentsEnabled'] ?? false),
            'maxAttachmentMb' => max(1, min(25, (int) ($settings['maxAttachmentMb'] ?? 10))),
            'allowedExtensions' => $allowed !== [] ? $allowed : ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'txt'],
            'autoAcknowledgeBooking' => (bool) ($settings['autoAcknowledgeBooking'] ?? false),
            'autoAcknowledgeRequests' => (bool) ($settings['autoAcknowledgeRequests'] ?? false),
            'supportReplyName' => trim((string) ($settings['supportReplyName'] ?? 'Nikki / Staff')) ?: 'Nikki / Staff',
            'bookingResponseDays' => max(1, min(30, (int) ($settings['bookingResponseDays'] ?? 3))),
        ];
        $this->setSetting('communications', $saved);
        return $saved;
    }

    /** @param array<string,mixed> $filters @return list<array<string,mixed>> */
    public function communicationThreads(array $filters = []): array
    {
        $status = trim((string) ($filters['status'] ?? ''));
        $type = trim((string) ($filters['type'] ?? ''));
        $memberId = trim((string) ($filters['memberId'] ?? ''));
        $search = strtolower(trim((string) ($filters['search'] ?? '')));

        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $rows = array_values((array) ($data['messageThreads'] ?? []));
            $members = (array) ($data['members'] ?? []);
            $rows = array_values(array_filter($rows, static function (array $thread) use ($status, $type, $memberId, $search, $members): bool {
                if ($status !== '' && (string) ($thread['status'] ?? '') !== $status) return false;
                if ($type !== '' && (string) ($thread['type'] ?? '') !== $type) return false;
                if ($memberId !== '' && (string) ($thread['memberId'] ?? '') !== $memberId) return false;
                if ($search !== '') {
                    $member = $members[(string) ($thread['memberId'] ?? '')] ?? [];
                    $haystack = strtolower(implode(' ', [
                        (string) ($thread['subject'] ?? ''),
                        (string) ($member['displayName'] ?? ''),
                        (string) ($member['email'] ?? ''),
                    ]));
                    if (!str_contains($haystack, $search)) return false;
                }
                return true;
            }));
            foreach ($rows as &$row) {
                $member = $members[(string) ($row['memberId'] ?? '')] ?? null;
                $row['member'] = is_array($member) ? $member : null;
            }
            unset($row);
            usort($rows, static fn(array $a, array $b): int => strcmp((string) ($b['lastMessageAt'] ?? $b['updatedAt'] ?? ''), (string) ($a['lastMessageAt'] ?? $a['updatedAt'] ?? '')));
            return $rows;
        }

        $where = ['1=1'];
        $params = [];
        if ($status !== '') { $where[] = 't.status = ?'; $params[] = $status; }
        if ($type !== '') { $where[] = 't.thread_type = ?'; $params[] = $type; }
        if ($memberId !== '') { $where[] = 't.member_id = ?'; $params[] = (int) $memberId; }
        if ($search !== '') {
            $where[] = '(LOWER(t.subject) LIKE ? OR LOWER(COALESCE(m.display_name,\'\')) LIKE ? OR LOWER(COALESCE(m.email,\'\')) LIKE ?)';
            $needle = '%' . $search . '%';
            array_push($params, $needle, $needle, $needle);
        }
        $sql = 'SELECT t.*, m.display_name AS member_display_name, m.email AS member_email FROM message_threads t LEFT JOIN members m ON m.id=t.member_id WHERE ' . implode(' AND ', $where) . ' ORDER BY COALESCE(t.last_message_at,t.updated_at) DESC, t.id DESC';
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map(fn(array $row): array => $this->mapCommunicationThreadRow($row), $stmt->fetchAll());
    }

    public function communicationThread(string|int $id): ?array
    {
        $key = (string) $id;
        if ($key === '') return null;
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $thread = $data['messageThreads'][$key] ?? null;
            if (!is_array($thread)) return null;
            $member = $data['members'][(string) ($thread['memberId'] ?? '')] ?? null;
            $thread['member'] = is_array($member) ? $member : null;
            return $thread;
        }
        $stmt = $this->pdo()->prepare('SELECT t.*, m.display_name AS member_display_name, m.email AS member_email FROM message_threads t LEFT JOIN members m ON m.id=t.member_id WHERE t.id=? LIMIT 1');
        $stmt->execute([(int) $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->mapCommunicationThreadRow($row) : null;
    }

    /** @param array<string,mixed> $input */
    public function createCommunicationThread(array $input): array
    {
        $subject = trim((string) ($input['subject'] ?? ''));
        if ($subject === '' || (function_exists('mb_strlen') ? mb_strlen($subject) : strlen($subject)) > 190) {
            throw new RuntimeException('Enter a subject of 190 characters or fewer.');
        }
        $type = (string) ($input['type'] ?? 'member');
        if (!in_array($type, ['member', 'content_request', 'booking'], true)) $type = 'member';
        $status = (string) ($input['status'] ?? 'pending_staff');
        if (!in_array($status, ['open', 'pending_member', 'pending_staff', 'closed', 'blocked'], true)) $status = 'pending_staff';
        $priority = (string) ($input['priority'] ?? 'normal');
        if (!in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) $priority = 'normal';
        $thread = [
            'id' => bin2hex(random_bytes(8)),
            'memberId' => ($input['memberId'] ?? null) !== null && (string) $input['memberId'] !== '' ? (string) $input['memberId'] : null,
            'type' => $type,
            'relatedType' => trim((string) ($input['relatedType'] ?? '')) ?: null,
            'relatedId' => trim((string) ($input['relatedId'] ?? '')) ?: null,
            'subject' => $subject,
            'status' => $status,
            'priority' => $priority,
            'assignedAdminId' => ($input['assignedAdminId'] ?? null) !== null ? (string) $input['assignedAdminId'] : null,
            'memberUnreadCount' => (int) ($input['memberUnreadCount'] ?? 0),
            'adminUnreadCount' => (int) ($input['adminUnreadCount'] ?? 0),
            'internalNotes' => trim((string) ($input['internalNotes'] ?? '')),
            'lastMessageAt' => null,
            'createdAt' => now_iso(),
            'updatedAt' => now_iso(),
        ];
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $data['messageThreads'][(string) $thread['id']] = $thread;
            $this->writeFileStore($data);
            return $this->communicationThread((string) $thread['id']) ?? $thread;
        }
        $stmt = $this->pdo()->prepare('INSERT INTO message_threads (member_id,thread_type,related_type,related_id,subject,status,priority,assigned_admin_id,member_unread_count,admin_unread_count,internal_notes,last_message_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,NULL,NOW(),NOW())');
        $stmt->execute([
            $thread['memberId'] !== null ? (int) $thread['memberId'] : null, $type, $thread['relatedType'], $thread['relatedId'], $subject,
            $status, $priority, $thread['assignedAdminId'] !== null ? (int) $thread['assignedAdminId'] : null,
            $thread['memberUnreadCount'], $thread['adminUnreadCount'], $thread['internalNotes'] !== '' ? $thread['internalNotes'] : null,
        ]);
        return $this->communicationThread((int) $this->pdo()->lastInsertId()) ?? $thread;
    }

    /** @param array<string,mixed> $changes */
    public function updateCommunicationThread(string|int $id, array $changes): array
    {
        $thread = $this->communicationThread($id);
        if (!$thread) throw new RuntimeException('Conversation not found.');
        foreach (['status' => ['open','pending_member','pending_staff','closed','blocked'], 'priority' => ['low','normal','high','urgent']] as $field => $allowed) {
            if (isset($changes[$field]) && in_array((string) $changes[$field], $allowed, true)) $thread[$field] = (string) $changes[$field];
        }
        if (array_key_exists('assignedAdminId', $changes)) $thread['assignedAdminId'] = ($changes['assignedAdminId'] === null || $changes['assignedAdminId'] === '') ? null : (string) $changes['assignedAdminId'];
        if (array_key_exists('internalNotes', $changes)) $thread['internalNotes'] = trim((string) $changes['internalNotes']);
        if (array_key_exists('memberUnreadCount', $changes)) $thread['memberUnreadCount'] = max(0, (int) $changes['memberUnreadCount']);
        if (array_key_exists('adminUnreadCount', $changes)) $thread['adminUnreadCount'] = max(0, (int) $changes['adminUnreadCount']);
        $thread['updatedAt'] = now_iso();
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $data['messageThreads'][(string) $thread['id']] = $thread;
            $this->writeFileStore($data);
            return $this->communicationThread((string) $thread['id']) ?? $thread;
        }
        $stmt = $this->pdo()->prepare('UPDATE message_threads SET status=?,priority=?,assigned_admin_id=?,member_unread_count=?,admin_unread_count=?,internal_notes=?,updated_at=NOW() WHERE id=?');
        $stmt->execute([$thread['status'],$thread['priority'],$thread['assignedAdminId'] !== null ? (int)$thread['assignedAdminId'] : null,$thread['memberUnreadCount'],$thread['adminUnreadCount'],$thread['internalNotes'] !== '' ? $thread['internalNotes'] : null,(int)$thread['id']]);
        return $this->communicationThread((int) $thread['id']) ?? $thread;
    }

    /** @return list<array<string,mixed>> */
    public function communicationMessages(string|int $threadId, bool $includeInternal = true): array
    {
        $key = (string) $threadId;
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $rows = array_values(array_filter((array) ($data['messages'] ?? []), static fn(array $message): bool => (string) ($message['threadId'] ?? '') === $key && ($includeInternal || (string) ($message['visibility'] ?? 'public') === 'public')));
            usort($rows, static fn(array $a,array $b):int => strcmp((string)($a['createdAt']??''),(string)($b['createdAt']??'')));
            foreach ($rows as &$row) $row['attachments'] = $this->messageAttachments((string) $row['id']);
            unset($row);
            return $rows;
        }
        $sql = 'SELECT * FROM messages WHERE thread_id=?' . ($includeInternal ? '' : " AND visibility='public'") . ' ORDER BY id ASC';
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute([(int) $threadId]);
        $rows = array_map(fn(array $row): array => $this->mapCommunicationMessageRow($row), $stmt->fetchAll());
        foreach ($rows as &$row) $row['attachments'] = $this->messageAttachments((string) $row['id']);
        unset($row);
        return $rows;
    }

    public function communicationMessage(string|int $messageId): ?array
    {
        $key = (string) $messageId;
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $message = $data['messages'][$key] ?? null;
            if (!is_array($message)) return null;
            $message['attachments'] = $this->messageAttachments($key);
            return $message;
        }
        $stmt = $this->pdo()->prepare('SELECT * FROM messages WHERE id=? LIMIT 1');
        $stmt->execute([(int) $messageId]);
        $row = $stmt->fetch();
        if (!is_array($row)) return null;
        $message = $this->mapCommunicationMessageRow($row);
        $message['attachments'] = $this->messageAttachments((string) $message['id']);
        return $message;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function sendCommunicationMessage(array $input): array
    {
        $thread = $this->communicationThread((string) ($input['threadId'] ?? ''));
        if (!$thread) throw new RuntimeException('Conversation not found.');
        $senderType = (string) ($input['senderType'] ?? 'member');
        if (!in_array($senderType, ['member','admin','system'], true)) $senderType = 'member';
        $visibility = (string) ($input['visibility'] ?? 'public');
        if (!in_array($visibility, ['public','internal'], true)) $visibility = 'public';
        $body = trim((string) ($input['body'] ?? ''));
        $attachments = array_values(array_filter((array) ($input['attachments'] ?? []), 'is_array'));
        $length = function_exists('mb_strlen') ? mb_strlen($body) : strlen($body);
        if (($body === '' && $attachments === []) || $length > 10000) throw new RuntimeException('Enter a message of 10,000 characters or fewer, or attach a file.');
        if ($senderType === 'member' && $this->isCommunicationBlocked((string) ($thread['memberId'] ?? ''), (string) (($thread['member']['email'] ?? '')), 'messaging')) {
            throw new RuntimeException('Messaging is not available for this account.');
        }
        $message = [
            'id' => bin2hex(random_bytes(8)),
            'threadId' => (string) $thread['id'],
            'senderType' => $senderType,
            'senderMemberId' => $senderType === 'member' ? (string) ($input['senderMemberId'] ?? $thread['memberId'] ?? '') : null,
            'senderAdminId' => $senderType === 'admin' ? (string) ($input['senderAdminId'] ?? '') : null,
            'visibility' => $visibility,
            'body' => $body,
            'createdAt' => now_iso(),
            'attachments' => [],
        ];
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $data['messages'][(string) $message['id']] = $message;
            foreach ($attachments as $attachment) {
                $record = $this->normalizeCommunicationAttachment($attachment, (string) $message['id'], 'message');
                $data['messageAttachments'][(string) $record['id']] = $record;
                $message['attachments'][] = $record;
            }
            $threadKey = (string) $thread['id'];
            $data['messageThreads'][$threadKey]['lastMessageAt'] = $message['createdAt'];
            $data['messageThreads'][$threadKey]['updatedAt'] = $message['createdAt'];
            if ($visibility === 'public') {
                if ($senderType === 'member') {
                    $data['messageThreads'][$threadKey]['status'] = 'pending_staff';
                    $data['messageThreads'][$threadKey]['adminUnreadCount'] = (int) ($data['messageThreads'][$threadKey]['adminUnreadCount'] ?? 0) + 1;
                } elseif ($senderType === 'admin') {
                    $data['messageThreads'][$threadKey]['status'] = 'pending_member';
                    $data['messageThreads'][$threadKey]['memberUnreadCount'] = (int) ($data['messageThreads'][$threadKey]['memberUnreadCount'] ?? 0) + 1;
                }
            }
            $this->writeFileStore($data);
        } else {
            $pdo = $this->pdo();
            $ownsTransaction = !$pdo->inTransaction();
            if ($ownsTransaction) $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('INSERT INTO messages (thread_id,sender_type,sender_member_id,sender_admin_id,visibility,body,created_at) VALUES (?,?,?,?,?,?,NOW())');
                $stmt->execute([(int)$thread['id'],$senderType,$message['senderMemberId'] !== null && $message['senderMemberId'] !== '' ? (int)$message['senderMemberId'] : null,$message['senderAdminId'] !== null && $message['senderAdminId'] !== '' ? (int)$message['senderAdminId'] : null,$visibility,$body]);
                $message['id'] = (string) $pdo->lastInsertId();
                foreach ($attachments as $attachment) {
                    $record = $this->normalizeCommunicationAttachment($attachment, (string) $message['id'], 'message');
                    $insert = $pdo->prepare('INSERT INTO message_attachments (message_id,file_path,original_name,mime_type,file_size,created_at) VALUES (?,?,?,?,?,NOW())');
                    $insert->execute([(int)$message['id'],$record['filePath'],$record['originalName'],$record['mimeType'],$record['fileSize']]);
                    $record['id'] = (string) $pdo->lastInsertId();
                    $message['attachments'][] = $record;
                }
                $status = (string) $thread['status'];
                $memberUnread = (int) $thread['memberUnreadCount'];
                $adminUnread = (int) $thread['adminUnreadCount'];
                if ($visibility === 'public') {
                    if ($senderType === 'member') { $status='pending_staff'; $adminUnread++; }
                    elseif ($senderType === 'admin') { $status='pending_member'; $memberUnread++; }
                }
                $update = $pdo->prepare('UPDATE message_threads SET status=?,member_unread_count=?,admin_unread_count=?,last_message_at=NOW(),updated_at=NOW() WHERE id=?');
                $update->execute([$status,$memberUnread,$adminUnread,(int)$thread['id']]);
                if ($ownsTransaction) $pdo->commit();
            } catch (\Throwable $e) {
                if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        }
        if ($senderType === 'admin' && $visibility === 'public' && !empty($thread['memberId'])) {
            $this->createMemberNotification((string) $thread['memberId'], 'message.reply', 'New private reply', 'A new reply is waiting in “' . (string) $thread['subject'] . '”.', 'messages/?thread=' . rawurlencode((string) $thread['id']));
        }
        return $message;
    }

    public function markCommunicationThreadRead(string|int $threadId, string $audience): void
    {
        $field = $audience === 'admin' ? 'adminUnreadCount' : 'memberUnreadCount';
        $this->updateCommunicationThread($threadId, [$field => 0]);
    }

    /** @return list<array<string,mixed>> */
    public function messageAttachments(string|int $messageId): array
    {
        $key = (string) $messageId;
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            return array_values(array_filter((array)($data['messageAttachments']??[]), static fn(array $a):bool=>(string)($a['messageId']??'')===$key));
        }
        $stmt=$this->pdo()->prepare('SELECT * FROM message_attachments WHERE message_id=? ORDER BY id');$stmt->execute([(int)$messageId]);
        return array_map(fn(array $r):array=>$this->mapCommunicationAttachmentRow($r,'message'),$stmt->fetchAll());
    }

    public function communicationAttachment(string|int $attachmentId): ?array
    {
        $key=(string)$attachmentId;
        if($this->mode==='file'){
            $data=$this->readFileStore();
            if(isset($data['messageAttachments'][$key])) return (array)$data['messageAttachments'][$key];
            if(isset($data['requestAttachments'][$key])) return (array)$data['requestAttachments'][$key];
            return null;
        }
        $stmt=$this->pdo()->prepare("SELECT ma.*, m.thread_id, 'message' AS attachment_kind FROM message_attachments ma JOIN messages m ON m.id=ma.message_id WHERE ma.id=? LIMIT 1");$stmt->execute([(int)$attachmentId]);$row=$stmt->fetch();
        if(is_array($row)) return $this->mapCommunicationAttachmentRow($row,'message');
        $stmt=$this->pdo()->prepare("SELECT ra.*, 'request' AS attachment_kind FROM request_attachments ra WHERE ra.id=? LIMIT 1");$stmt->execute([(int)$attachmentId]);$row=$stmt->fetch();
        return is_array($row)?$this->mapCommunicationAttachmentRow($row,'request'):null;
    }

    /** @param array<string,mixed> $input */
    public function createContentRequest(array $input): array
    {
        $memberId=trim((string)($input['memberId']??''));
        if($memberId===''||!$this->member($memberId)) throw new RuntimeException('A valid member account is required.');
        $member=$this->member($memberId);
        if($this->isCommunicationBlocked($memberId,(string)($member['email']??''),'requests')) throw new RuntimeException('Content requests are not available for this account.');
        $title=trim((string)($input['title']??''));$description=trim((string)($input['description']??''));
        if($title===''||(function_exists('mb_strlen')?mb_strlen($title):strlen($title))>190) throw new RuntimeException('Enter a request title of 190 characters or fewer.');
        if($description===''||(function_exists('mb_strlen')?mb_strlen($description):strlen($description))>10000) throw new RuntimeException('Describe the request in 10,000 characters or fewer.');
        $category=(string)($input['category']??'general');
        if(!in_array($category,['photo','video','audio','custom','general'],true))$category='general';
        $request=[
            'id'=>bin2hex(random_bytes(8)),'memberId'=>$memberId,'threadId'=>null,'category'=>$category,'title'=>$title,'description'=>$description,
            'budgetMin'=>($input['budgetMin']??'')!==''?(float)$input['budgetMin']:null,'budgetMax'=>($input['budgetMax']??'')!==''?(float)$input['budgetMax']:null,
            'currency'=>strtoupper(substr((string)($input['currency']??'USD'),0,3)),'requestedDueAt'=>trim((string)($input['requestedDueAt']??''))?:null,
            'status'=>'submitted','quotedAmount'=>null,'adminNotes'=>'','createdAt'=>now_iso(),'updatedAt'=>now_iso(),
        ];
        if($this->mode==='file'){
            $data=$this->readFileStore();$data['contentRequests'][(string)$request['id']]=$request;$this->writeFileStore($data);
        }else{
            $stmt=$this->pdo()->prepare('INSERT INTO content_requests (member_id,thread_id,category,title,description,budget_min,budget_max,currency,requested_due_at,status,quoted_amount,admin_notes,created_at,updated_at) VALUES (?,NULL,?,?,?,?,?,?,?,?,NULL,NULL,NOW(),NOW())');
            $stmt->execute([(int)$memberId,$category,$title,$description,$request['budgetMin'],$request['budgetMax'],$request['currency'],$request['requestedDueAt']?:null,'submitted']);$request['id']=(string)$this->pdo()->lastInsertId();
        }
        $thread=$this->createCommunicationThread(['memberId'=>$memberId,'type'=>'content_request','relatedType'=>'content_request','relatedId'=>$request['id'],'subject'=>'Content Request: '.$title,'status'=>'pending_staff','adminUnreadCount'=>0]);
        $request['threadId']=(string)$thread['id'];
        $this->setContentRequestThread((string)$request['id'],(string)$thread['id']);
        $this->sendCommunicationMessage(['threadId'=>$thread['id'],'senderType'=>'member','senderMemberId'=>$memberId,'body'=>$description,'attachments'=>(array)($input['attachments']??[])]);
        foreach((array)($input['attachments']??[]) as $attachment) if(is_array($attachment)) $this->addRequestAttachment('content_request',(string)$request['id'],$attachment,'member');
        return $this->contentRequest((string)$request['id'])??$request;
    }

    /** @param array<string,mixed> $filters @return list<array<string,mixed>> */
    public function contentRequests(array $filters=[]): array
    {
        $memberId=trim((string)($filters['memberId']??''));$status=trim((string)($filters['status']??''));$search=strtolower(trim((string)($filters['search']??'')));
        if($this->mode==='file'){
            $data=$this->readFileStore();$rows=array_values((array)($data['contentRequests']??[]));
            $rows=array_values(array_filter($rows,static function(array $r)use($memberId,$status,$search):bool{if($memberId!==''&&(string)($r['memberId']??'')!==$memberId)return false;if($status!==''&&(string)($r['status']??'')!==$status)return false;if($search!==''&&!str_contains(strtolower((string)($r['title']??'').' '.(string)($r['description']??'')),$search))return false;return true;}));
            foreach($rows as &$r){$r['member']=$data['members'][(string)($r['memberId']??'')]??null;$r['attachments']=$this->requestAttachments('content_request',(string)$r['id']);}unset($r);
            usort($rows,static fn(array $a,array $b):int=>strcmp((string)($b['createdAt']??''),(string)($a['createdAt']??'')));return $rows;
        }
        $where=['1=1'];$params=[];if($memberId!==''){$where[]='r.member_id=?';$params[]=(int)$memberId;}if($status!==''){$where[]='r.status=?';$params[]=$status;}if($search!==''){$where[]='(LOWER(r.title) LIKE ? OR LOWER(r.description) LIKE ?)';$needle='%'.$search.'%';$params[]=$needle;$params[]=$needle;}
        $stmt=$this->pdo()->prepare('SELECT r.*,m.display_name AS member_display_name,m.email AS member_email FROM content_requests r JOIN members m ON m.id=r.member_id WHERE '.implode(' AND ',$where).' ORDER BY r.id DESC');$stmt->execute($params);
        $rows=array_map(fn(array $r):array=>$this->mapContentRequestRow($r),$stmt->fetchAll());foreach($rows as &$r)$r['attachments']=$this->requestAttachments('content_request',(string)$r['id']);unset($r);return $rows;
    }

    public function contentRequest(string|int $id): ?array
    {
        foreach($this->contentRequests([]) as $request) if((string)$request['id']===(string)$id)return $request;return null;
    }

    /** @param array<string,mixed> $changes */
    public function updateContentRequest(string|int $id,array $changes):array
    {
        $request=$this->contentRequest($id);if(!$request)throw new RuntimeException('Content request not found.');
        $allowed=['submitted','reviewing','quoted','accepted','declined','completed','cancelled'];$oldStatus=(string)$request['status'];
        if(isset($changes['status'])&&in_array((string)$changes['status'],$allowed,true))$request['status']=(string)$changes['status'];
        if(array_key_exists('quotedAmount',$changes))$request['quotedAmount']=$changes['quotedAmount']===''||$changes['quotedAmount']===null?null:max(0,(float)$changes['quotedAmount']);
        if(array_key_exists('adminNotes',$changes))$request['adminNotes']=trim((string)$changes['adminNotes']);
        $request['updatedAt']=now_iso();
        if($this->mode==='file'){$data=$this->readFileStore();$data['contentRequests'][(string)$request['id']]=$request;$this->writeFileStore($data);}else{$stmt=$this->pdo()->prepare('UPDATE content_requests SET status=?,quoted_amount=?,admin_notes=?,updated_at=NOW() WHERE id=?');$stmt->execute([$request['status'],$request['quotedAmount'],$request['adminNotes']!==''?$request['adminNotes']:null,(int)$request['id']]);}
        if($oldStatus!==$request['status']&&!empty($request['threadId']))$this->sendCommunicationMessage(['threadId'=>$request['threadId'],'senderType'=>'system','visibility'=>'public','body'=>'Request status changed to '.ucwords(str_replace('_',' ',(string)$request['status'])).'.']);
        return $this->contentRequest($id)??$request;
    }

    /** @param array<string,mixed> $input */
    public function createBookingRequest(array $input): array
    {
        $submission = null;
        if (array_key_exists('formValues', $input)) {
            if (!is_array($input['formValues'])) throw new RuntimeException('The booking answers are invalid.');
            $validation = (new BookingFormService($this))->validate($input['formValues'], (string) ($input['formRevision'] ?? ''));
            if ($validation['errors'] !== []) throw new BookingFormValidationException($validation['errors']);
            $this->assertBookingFormStorageReady();
            $input = array_merge($input, $validation['mapped']);
            $submission = $validation['submission'];
        }
        $memberId = trim((string) ($input['memberId'] ?? ''));
        $name = trim((string) ($input['contactName'] ?? ''));
        $email = strtolower(trim((string) ($input['contactEmail'] ?? '')));
        $details = trim((string) ($input['details'] ?? ''));
        if (($submission === null && $name === '') || (function_exists('mb_strlen') ? mb_strlen($name) : strlen($name)) > 120) {
            throw new RuntimeException('Enter your name.');
        }
        if (($submission === null || $email !== '') && !BookingFormService::validEmail($email)) throw new RuntimeException('Enter a valid email address.');
        if (($submission === null && $details === '') || (function_exists('mb_strlen') ? mb_strlen($details) : strlen($details)) > 10000) {
            throw new RuntimeException('Describe the inquiry in 10,000 characters or fewer.');
        }
        if ($this->isCommunicationBlocked($memberId, $email, 'requests')) throw new RuntimeException('Booking inquiries are not available for this account or email address.');
        $type = (string) ($input['inquiryType'] ?? 'appearance');
        if (!in_array($type, ['appearance','collaboration','interview','event','media','other'], true)) $type = 'other';
        $request = ['id' => bin2hex(random_bytes(8)), 'memberId' => $memberId !== '' ? $memberId : null, 'threadId' => null,
            'inquiryType' => $type, 'contactName' => $name, 'contactEmail' => $email, 'phone' => trim((string) ($input['phone'] ?? '')),
            'requestedDate' => trim((string) ($input['requestedDate'] ?? '')) ?: null, 'city' => trim((string) ($input['city'] ?? '')),
            'venue' => trim((string) ($input['venue'] ?? '')), 'budget' => trim((string) ($input['budget'] ?? '')), 'details' => $details,
            'status' => 'submitted', 'priority' => 'normal', 'assignedAdminId' => null, 'internalNotes' => '', 'consentAt' => now_iso(),
            'createdAt' => now_iso(), 'updatedAt' => now_iso()];
        $transaction = $this->mode !== 'file' && !$this->pdo()->inTransaction();
        if ($transaction) $this->pdo()->beginTransaction();
        try {
            if ($this->mode === 'file') {
                $data = $this->readFileStore();
                $data['bookingRequests'][(string) $request['id']] = $request;
                $this->writeFileStore($data);
            } else {
                $stmt = $this->pdo()->prepare('INSERT INTO booking_requests (member_id,thread_id,inquiry_type,contact_name,contact_email,phone,requested_date,city,venue,budget,details,status,priority,assigned_admin_id,internal_notes,consent_at,created_at,updated_at) VALUES (?,NULL,?,?,?,?,?,?,?,?,?,?,?,NULL,NULL,NOW(),NOW(),NOW())');
                $stmt->execute([$request['memberId'] !== null ? (int) $request['memberId'] : null, $type, $name, $email,
                    $request['phone'] ?: null, $request['requestedDate'] ?: null, $request['city'] ?: null, $request['venue'] ?: null,
                    $request['budget'] ?: null, $details, 'submitted', 'normal']);
                $request['id'] = (string) $this->pdo()->lastInsertId();
            }
            if ($submission !== null) $this->saveBookingFormSubmission($request['id'], $submission);
            $thread = $this->createCommunicationThread(['memberId' => $request['memberId'], 'type' => 'booking',
                'relatedType' => 'booking_request', 'relatedId' => $request['id'],
                'subject' => 'Booking Inquiry: ' . ucwords(str_replace('_', ' ', $type)), 'status' => 'pending_staff']);
            $request['threadId'] = (string) $thread['id'];
            $this->setBookingRequestThread((string) $request['id'], (string) $thread['id']);
            $body = "Inquiry type: " . ucwords(str_replace('_', ' ', $type)) . "\nRequested date: " . ($request['requestedDate'] ?: 'Not specified')
                . "\nCity: " . ($request['city'] ?: 'Not specified') . "\nVenue/platform: " . ($request['venue'] ?: 'Not specified')
                . "\nBudget: " . ($request['budget'] ?: 'Not specified') . "\n\n" . $details;
            if ($submission !== null) {
                $body = "Booking form submitted.\n";
                foreach ($submission['fields'] as $field) {
                    $answer = BookingFormService::displayValue($field, $submission['values'][$field['id']] ?? '');
                    if ($answer !== '') $body .= "\n" . $field['label'] . ': ' . $answer;
                }
                // The immutable submission keeps every answer even when the conversation summary is abbreviated.
                if ((function_exists('mb_strlen') ? mb_strlen($body) : strlen($body)) > 9500) $body = (function_exists('mb_substr') ? mb_substr($body, 0, 9500, 'UTF-8') : substr($body, 0, 9500))
                    . "\n[Full answers are available in the booking inquiry.]";
            }
            $this->sendCommunicationMessage(['threadId' => $thread['id'], 'senderType' => 'member', 'senderMemberId' => $request['memberId'],
                'body' => $body, 'attachments' => (array) ($input['attachments'] ?? [])]);
            foreach ((array) ($input['attachments'] ?? []) as $attachment) {
                if (is_array($attachment)) $this->addRequestAttachment('booking_request', (string) $request['id'], $attachment, $memberId !== '' ? 'member' : 'guest');
            }
            if ($transaction) $this->pdo()->commit();
        } catch (\Throwable $error) {
            if ($transaction && $this->pdo()->inTransaction()) $this->pdo()->rollBack();
            throw $error;
        }
        return $this->bookingRequest((string) $request['id']) ?? $request;
    }

    /** @param array<string,mixed> $filters @return list<array<string,mixed>> */
    public function bookingRequests(array $filters=[]):array
    {
        $memberId=trim((string)($filters['memberId']??''));$status=trim((string)($filters['status']??''));$search=strtolower(trim((string)($filters['search']??'')));
        if($this->mode==='file'){$data=$this->readFileStore();$rows=array_values((array)($data['bookingRequests']??[]));$rows=array_values(array_filter($rows,static function(array $r)use($memberId,$status,$search):bool{if($memberId!==''&&(string)($r['memberId']??'')!==$memberId)return false;if($status!==''&&(string)($r['status']??'')!==$status)return false;if($search!==''&&!str_contains(strtolower(implode(' ',[(string)($r['contactName']??''),(string)($r['contactEmail']??''),(string)($r['details']??''),(string)($r['city']??'')])), $search))return false;return true;}));foreach($rows as &$r){$r['member']=$data['members'][(string)($r['memberId']??'')]??null;$r['attachments']=$this->requestAttachments('booking_request',(string)$r['id']);}unset($r);usort($rows,static fn(array $a,array $b):int=>strcmp((string)($b['createdAt']??''),(string)($a['createdAt']??'')));return $rows;}
        $where=['1=1'];$params=[];if($memberId!==''){$where[]='r.member_id=?';$params[]=(int)$memberId;}if($status!==''){$where[]='r.status=?';$params[]=$status;}if($search!==''){$where[]='(LOWER(r.contact_name) LIKE ? OR LOWER(r.contact_email) LIKE ? OR LOWER(r.details) LIKE ? OR LOWER(COALESCE(r.city,\'\')) LIKE ?)';$n='%'.$search.'%';array_push($params,$n,$n,$n,$n);} $stmt=$this->pdo()->prepare('SELECT r.*,m.display_name AS member_display_name,m.email AS member_email FROM booking_requests r LEFT JOIN members m ON m.id=r.member_id WHERE '.implode(' AND ',$where).' ORDER BY r.id DESC');$stmt->execute($params);$rows=array_map(fn(array $r):array=>$this->mapBookingRequestRow($r),$stmt->fetchAll());foreach($rows as &$r)$r['attachments']=$this->requestAttachments('booking_request',(string)$r['id']);unset($r);return $rows;
    }

    public function bookingRequest(string|int $id): ?array
    {
        foreach ($this->bookingRequests([]) as $request) {
            if ((string) $request['id'] === (string) $id) {
                $request['formSubmission'] = $this->bookingFormSubmission($id);
                return $request;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $changes */
    public function updateBookingRequest(string|int $id,array $changes):array
    {
        $r=$this->bookingRequest($id);if(!$r)throw new RuntimeException('Booking request not found.');$old=(string)$r['status'];$statuses=['submitted','reviewing','quoted','accepted','declined','completed','cancelled'];$priorities=['low','normal','high','urgent'];if(isset($changes['status'])&&in_array((string)$changes['status'],$statuses,true))$r['status']=(string)$changes['status'];if(isset($changes['priority'])&&in_array((string)$changes['priority'],$priorities,true))$r['priority']=(string)$changes['priority'];if(array_key_exists('assignedAdminId',$changes))$r['assignedAdminId']=$changes['assignedAdminId']===''||$changes['assignedAdminId']===null?null:(string)$changes['assignedAdminId'];if(array_key_exists('internalNotes',$changes))$r['internalNotes']=trim((string)$changes['internalNotes']);$r['updatedAt']=now_iso();
        if($this->mode==='file'){$data=$this->readFileStore();$data['bookingRequests'][(string)$r['id']]=$r;$this->writeFileStore($data);}else{$stmt=$this->pdo()->prepare('UPDATE booking_requests SET status=?,priority=?,assigned_admin_id=?,internal_notes=?,updated_at=NOW() WHERE id=?');$stmt->execute([$r['status'],$r['priority'],$r['assignedAdminId']!==null?(int)$r['assignedAdminId']:null,$r['internalNotes']!==''?$r['internalNotes']:null,(int)$r['id']]);}
        if($old!==$r['status']&&!empty($r['threadId']))$this->sendCommunicationMessage(['threadId'=>$r['threadId'],'senderType'=>'system','visibility'=>'public','body'=>'Booking inquiry status changed to '.ucwords(str_replace('_',' ',(string)$r['status'])).'.']);return $this->bookingRequest($id)??$r;
    }

    /** @return list<array<string,mixed>> */
    public function requestAttachments(string $requestType,string|int $requestId):array
    {
        if($this->mode==='file'){$data=$this->readFileStore();return array_values(array_filter((array)($data['requestAttachments']??[]),static fn(array $a):bool=>(string)($a['requestType']??'')===$requestType&&(string)($a['requestId']??'')===(string)$requestId));}
        $stmt=$this->pdo()->prepare('SELECT * FROM request_attachments WHERE request_type=? AND request_id=? ORDER BY id');$stmt->execute([$requestType,(int)$requestId]);return array_map(fn(array $r):array=>$this->mapCommunicationAttachmentRow($r,'request'),$stmt->fetchAll());
    }

    /** @param array<string,mixed> $attachment */
    public function addRequestAttachment(string $requestType,string|int $requestId,array $attachment,string $uploadedByType='member'):array
    {
        $record=$this->normalizeCommunicationAttachment($attachment,null,'request');$record['requestType']=$requestType;$record['requestId']=(string)$requestId;$record['uploadedByType']=$uploadedByType;
        if($this->mode==='file'){$data=$this->readFileStore();$data['requestAttachments'][(string)$record['id']]=$record;$this->writeFileStore($data);return $record;}
        $stmt=$this->pdo()->prepare('INSERT INTO request_attachments (request_type,request_id,file_path,original_name,mime_type,file_size,uploaded_by_type,created_at) VALUES (?,?,?,?,?,?,?,NOW())');$stmt->execute([$requestType,(int)$requestId,$record['filePath'],$record['originalName'],$record['mimeType'],$record['fileSize'],$uploadedByType]);$record['id']=(string)$this->pdo()->lastInsertId();return $record;
    }

    /** @return list<array<string,mixed>> */
    public function savedReplies(bool $activeOnly=true):array
    {
        if($this->mode==='file'){$data=$this->readFileStore();$rows=array_values((array)($data['savedReplies']??[]));if($activeOnly)$rows=array_values(array_filter($rows,static fn(array $r):bool=>!empty($r['isActive'])));usort($rows,static fn(array $a,array $b):int=>((int)($a['sortOrder']??100)<=> (int)($b['sortOrder']??100)));return $rows;}
        $stmt=$this->pdo()->query('SELECT * FROM saved_replies'.($activeOnly?' WHERE is_active=1':'').' ORDER BY sort_order,id');return array_map(static fn(array $r):array=>['id'=>(string)$r['id'],'title'=>$r['title'],'category'=>$r['category'],'body'=>$r['body'],'isActive'=>(bool)$r['is_active'],'sortOrder'=>(int)$r['sort_order'],'createdAt'=>$r['created_at'],'updatedAt'=>$r['updated_at']],$stmt->fetchAll());
    }

    /** @param array<string,mixed> $input */
    public function saveSavedReply(array $input):array
    {
        $title=trim((string)($input['title']??''));$body=trim((string)($input['body']??''));if($title===''||$body==='')throw new RuntimeException('Saved replies need a title and message.');$id=trim((string)($input['id']??''));$reply=['id'=>$id!==''?$id:bin2hex(random_bytes(8)),'title'=>$title,'category'=>trim((string)($input['category']??'general'))?:'general','body'=>$body,'isActive'=>(bool)($input['isActive']??true),'sortOrder'=>(int)($input['sortOrder']??100),'createdAt'=>now_iso(),'updatedAt'=>now_iso()];
        if($this->mode==='file'){$data=$this->readFileStore();if($id!==''&&isset($data['savedReplies'][$id]))$reply['createdAt']=$data['savedReplies'][$id]['createdAt']??now_iso();$data['savedReplies'][(string)$reply['id']]=$reply;$this->writeFileStore($data);return $reply;}
        if($id!==''&&ctype_digit($id)){$stmt=$this->pdo()->prepare('UPDATE saved_replies SET title=?,category=?,body=?,is_active=?,sort_order=?,updated_at=NOW() WHERE id=?');$stmt->execute([$title,$reply['category'],$body,$reply['isActive']?1:0,$reply['sortOrder'],(int)$id]);$reply['id']=$id;}else{$stmt=$this->pdo()->prepare('INSERT INTO saved_replies (title,category,body,is_active,sort_order,created_at,updated_at) VALUES (?,?,?,?,?,NOW(),NOW())');$stmt->execute([$title,$reply['category'],$body,$reply['isActive']?1:0,$reply['sortOrder']]);$reply['id']=(string)$this->pdo()->lastInsertId();}return $reply;
    }

    public function isCommunicationBlocked(?string $memberId,?string $email,string $scope='all'):bool
    {
        $memberId=trim((string)$memberId);$email=strtolower(trim((string)$email));$now=time();
        foreach($this->communicationBlocks() as $block){if(!empty($block['expiresAt'])&&strtotime((string)$block['expiresAt'])<=$now)continue;$matches=($memberId!==''&&(string)($block['memberId']??'')===$memberId)||($email!==''&&strtolower((string)($block['email']??''))===$email);if(!$matches)continue;$blockScope=(string)($block['scope']??'all');if($blockScope==='all'||$blockScope===$scope||($scope==='requests'&&$blockScope==='messaging'))return true;}return false;
    }

    /** @return list<array<string,mixed>> */
    public function communicationBlocks():array
    {
        if($this->mode==='file'){$data=$this->readFileStore();return array_values((array)($data['communicationBlocks']??[]));}
        $rows=$this->pdo()->query('SELECT * FROM communication_blocks ORDER BY id DESC')->fetchAll();return array_map(static fn(array $r):array=>['id'=>(string)$r['id'],'memberId'=>$r['member_id']!==null?(string)$r['member_id']:null,'email'=>$r['email'],'scope'=>$r['scope'],'reason'=>$r['reason'],'blockedByAdminId'=>$r['blocked_by_admin_id']!==null?(string)$r['blocked_by_admin_id']:null,'expiresAt'=>$r['expires_at'],'createdAt'=>$r['created_at'],'updatedAt'=>$r['updated_at']],$rows);
    }

    /** @param array<string,mixed> $input */
    public function blockCommunication(array $input):array
    {
        $memberId=trim((string)($input['memberId']??''));$email=strtolower(trim((string)($input['email']??'')));if($memberId===''&&$email==='')throw new RuntimeException('Choose a member or enter an email address to restrict.');$scope=(string)($input['scope']??'all');if(!in_array($scope,['messaging','requests','all'],true))$scope='all';$record=['id'=>bin2hex(random_bytes(8)),'memberId'=>$memberId!==''?$memberId:null,'email'=>$email!==''?$email:null,'scope'=>$scope,'reason'=>trim((string)($input['reason']??'')),'blockedByAdminId'=>($input['adminId']??null)!==null?(string)$input['adminId']:null,'expiresAt'=>trim((string)($input['expiresAt']??''))?:null,'createdAt'=>now_iso(),'updatedAt'=>now_iso()];
        if($this->mode==='file'){$data=$this->readFileStore();$data['communicationBlocks'][(string)$record['id']]=$record;$this->writeFileStore($data);return $record;}
        $stmt=$this->pdo()->prepare('INSERT INTO communication_blocks (member_id,email,scope,reason,blocked_by_admin_id,expires_at,created_at,updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW())');$stmt->execute([$record['memberId']!==null?(int)$record['memberId']:null,$record['email'],$scope,$record['reason']!==''?$record['reason']:null,$record['blockedByAdminId']!==null?(int)$record['blockedByAdminId']:null,$record['expiresAt']]);$record['id']=(string)$this->pdo()->lastInsertId();return $record;
    }

    public function removeCommunicationBlock(string|int $id):void
    {
        if($this->mode==='file'){$data=$this->readFileStore();unset($data['communicationBlocks'][(string)$id]);$this->writeFileStore($data);return;}$stmt=$this->pdo()->prepare('DELETE FROM communication_blocks WHERE id=?');$stmt->execute([(int)$id]);
    }

    /** @param array<string,mixed> $metadata */
    public function createMemberNotification(string|int $memberId,string $type,string $title,string $body,string $url='',array $metadata=[]):array
    {
        $record=['id'=>bin2hex(random_bytes(8)),'memberId'=>(string)$memberId,'type'=>$type,'title'=>$title,'body'=>$body,'url'=>$url,'metadata'=>$metadata,'readAt'=>null,'createdAt'=>now_iso()];
        if($this->mode==='file'){$data=$this->readFileStore();$data['memberNotifications'][(string)$record['id']]=$record;$this->writeFileStore($data);return $record;}
        $stmt=$this->pdo()->prepare('INSERT INTO member_notifications (member_id,notification_type,title,body,target_url,metadata_json,read_at,created_at) VALUES (?,?,?,?,?,?,NULL,NOW())');$stmt->execute([(int)$memberId,$type,$title,$body,$url!==''?$url:null,json_encode($metadata)]);$record['id']=(string)$this->pdo()->lastInsertId();return $record;
    }

    /** @return list<array<string,mixed>> */
    public function memberNotifications(string|int $memberId,bool $unreadOnly=false):array
    {
        if($this->mode==='file'){$data=$this->readFileStore();$rows=array_values(array_filter((array)($data['memberNotifications']??[]),static fn(array $n):bool=>(string)($n['memberId']??'')===(string)$memberId&&(!$unreadOnly||empty($n['readAt']))));usort($rows,static fn(array $a,array $b):int=>strcmp((string)($b['createdAt']??''),(string)($a['createdAt']??'')));return $rows;}
        $stmt=$this->pdo()->prepare('SELECT * FROM member_notifications WHERE member_id=?'.($unreadOnly?' AND read_at IS NULL':'').' ORDER BY id DESC');$stmt->execute([(int)$memberId]);return array_map(static fn(array $r):array=>['id'=>(string)$r['id'],'memberId'=>(string)$r['member_id'],'type'=>$r['notification_type'],'title'=>$r['title'],'body'=>$r['body'],'url'=>$r['target_url'],'metadata'=>safe_json_decode((string)($r['metadata_json']??'')),'readAt'=>$r['read_at'],'createdAt'=>$r['created_at']],$stmt->fetchAll());
    }

    public function markMemberNotificationRead(string|int $notificationId,string|int $memberId):void
    {
        if($this->mode==='file'){$data=$this->readFileStore();$key=(string)$notificationId;if(isset($data['memberNotifications'][$key])&&(string)($data['memberNotifications'][$key]['memberId']??'')===(string)$memberId)$data['memberNotifications'][$key]['readAt']=now_iso();$this->writeFileStore($data);return;}$stmt=$this->pdo()->prepare('UPDATE member_notifications SET read_at=NOW() WHERE id=? AND member_id=?');$stmt->execute([(int)$notificationId,(int)$memberId]);
    }

    /** @return array<string,int> */
    public function communicationStats():array
    {
        $threads=$this->communicationThreads();$bookings=$this->bookingRequests();$requests=$this->contentRequests();return ['threads'=>count($threads),'unreadThreads'=>count(array_filter($threads,static fn(array $t):bool=>(int)($t['adminUnreadCount']??0)>0)),'openThreads'=>count(array_filter($threads,static fn(array $t):bool=>!in_array((string)($t['status']??''),['closed','blocked'],true))),'bookingRequests'=>count($bookings),'openBookings'=>count(array_filter($bookings,static fn(array $r):bool=>!in_array((string)($r['status']??''),['declined','completed','cancelled'],true))),'contentRequests'=>count($requests),'openContentRequests'=>count(array_filter($requests,static fn(array $r):bool=>!in_array((string)($r['status']??''),['declined','completed','cancelled'],true)))];
    }

    private function setContentRequestThread(string $requestId,string $threadId):void
    {
        if($this->mode==='file'){$data=$this->readFileStore();if(isset($data['contentRequests'][$requestId])){$data['contentRequests'][$requestId]['threadId']=$threadId;$data['contentRequests'][$requestId]['updatedAt']=now_iso();}$this->writeFileStore($data);return;}$stmt=$this->pdo()->prepare('UPDATE content_requests SET thread_id=?,updated_at=NOW() WHERE id=?');$stmt->execute([(int)$threadId,(int)$requestId]);
    }

    private function setBookingRequestThread(string $requestId,string $threadId):void
    {
        if($this->mode==='file'){$data=$this->readFileStore();if(isset($data['bookingRequests'][$requestId])){$data['bookingRequests'][$requestId]['threadId']=$threadId;$data['bookingRequests'][$requestId]['updatedAt']=now_iso();}$this->writeFileStore($data);return;}$stmt=$this->pdo()->prepare('UPDATE booking_requests SET thread_id=?,updated_at=NOW() WHERE id=?');$stmt->execute([(int)$threadId,(int)$requestId]);
    }

    /** @param array<string,mixed> $attachment @return array<string,mixed> */
    private function normalizeCommunicationAttachment(array $attachment,?string $messageId,string $kind):array
    {
        return ['id'=>bin2hex(random_bytes(8)),'kind'=>$kind,'messageId'=>$messageId,'filePath'=>(string)($attachment['filePath']??$attachment['file']??''),'originalName'=>(string)($attachment['originalName']??$attachment['name']??'attachment'),'mimeType'=>(string)($attachment['mimeType']??$attachment['mime']??'application/octet-stream'),'fileSize'=>(int)($attachment['fileSize']??$attachment['size']??0),'createdAt'=>now_iso()];
    }

    /** @param array<string,mixed> $row */
    private function mapCommunicationThreadRow(array $row):array
    {
        return ['id'=>(string)$row['id'],'memberId'=>$row['member_id']!==null?(string)$row['member_id']:null,'type'=>$row['thread_type'],'relatedType'=>$row['related_type'],'relatedId'=>$row['related_id']!==null?(string)$row['related_id']:null,'subject'=>$row['subject'],'status'=>$row['status'],'priority'=>$row['priority'],'assignedAdminId'=>$row['assigned_admin_id']!==null?(string)$row['assigned_admin_id']:null,'memberUnreadCount'=>(int)$row['member_unread_count'],'adminUnreadCount'=>(int)$row['admin_unread_count'],'internalNotes'=>(string)($row['internal_notes']??''),'lastMessageAt'=>$row['last_message_at'],'createdAt'=>$row['created_at'],'updatedAt'=>$row['updated_at'],'member'=>isset($row['member_display_name'])?['id'=>$row['member_id']!==null?(string)$row['member_id']:null,'displayName'=>(string)$row['member_display_name'],'email'=>(string)$row['member_email']]:null];
    }

    private function mapCommunicationMessageRow(array $row):array{return ['id'=>(string)$row['id'],'threadId'=>(string)$row['thread_id'],'senderType'=>$row['sender_type'],'senderMemberId'=>$row['sender_member_id']!==null?(string)$row['sender_member_id']:null,'senderAdminId'=>$row['sender_admin_id']!==null?(string)$row['sender_admin_id']:null,'visibility'=>$row['visibility'],'body'=>$row['body'],'createdAt'=>$row['created_at'],'attachments'=>[]];}

    private function mapCommunicationAttachmentRow(array $row,string $kind):array{return ['id'=>(string)$row['id'],'kind'=>$kind,'messageId'=>isset($row['message_id'])?(string)$row['message_id']:null,'threadId'=>isset($row['thread_id'])?(string)$row['thread_id']:null,'requestType'=>$row['request_type']??null,'requestId'=>isset($row['request_id'])?(string)$row['request_id']:null,'filePath'=>$row['file_path'],'originalName'=>$row['original_name'],'mimeType'=>$row['mime_type'],'fileSize'=>(int)$row['file_size'],'uploadedByType'=>$row['uploaded_by_type']??null,'createdAt'=>$row['created_at']];}

    private function mapContentRequestRow(array $r):array{return ['id'=>(string)$r['id'],'memberId'=>(string)$r['member_id'],'threadId'=>$r['thread_id']!==null?(string)$r['thread_id']:null,'category'=>$r['category'],'title'=>$r['title'],'description'=>$r['description'],'budgetMin'=>$r['budget_min']!==null?(float)$r['budget_min']:null,'budgetMax'=>$r['budget_max']!==null?(float)$r['budget_max']:null,'currency'=>$r['currency'],'requestedDueAt'=>$r['requested_due_at'],'status'=>$r['status'],'quotedAmount'=>$r['quoted_amount']!==null?(float)$r['quoted_amount']:null,'adminNotes'=>(string)($r['admin_notes']??''),'createdAt'=>$r['created_at'],'updatedAt'=>$r['updated_at'],'member'=>['id'=>(string)$r['member_id'],'displayName'=>(string)($r['member_display_name']??''),'email'=>(string)($r['member_email']??'')]];}

    private function mapBookingRequestRow(array $r):array{return ['id'=>(string)$r['id'],'memberId'=>$r['member_id']!==null?(string)$r['member_id']:null,'threadId'=>$r['thread_id']!==null?(string)$r['thread_id']:null,'inquiryType'=>$r['inquiry_type'],'contactName'=>$r['contact_name'],'contactEmail'=>$r['contact_email'],'phone'=>(string)($r['phone']??''),'requestedDate'=>$r['requested_date'],'city'=>(string)($r['city']??''),'venue'=>(string)($r['venue']??''),'budget'=>(string)($r['budget']??''),'details'=>$r['details'],'status'=>$r['status'],'priority'=>$r['priority'],'assignedAdminId'=>$r['assigned_admin_id']!==null?(string)$r['assigned_admin_id']:null,'internalNotes'=>(string)($r['internal_notes']??''),'consentAt'=>$r['consent_at'],'createdAt'=>$r['created_at'],'updatedAt'=>$r['updated_at'],'member'=>$r['member_id']!==null?['id'=>(string)$r['member_id'],'displayName'=>(string)($r['member_display_name']??''),'email'=>(string)($r['member_email']??'')]:null];}
}
