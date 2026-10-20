<?php
declare(strict_types=1);

namespace App\Core;

use PDOException;
use RuntimeException;

trait BookingFormStoreTrait
{
    public function bookingFormSubmission(string|int $requestId): ?array
    {
        if ($this->mode === 'file') {
            return $this->readFileStore()['bookingFormSubmissions'][(string) $requestId] ?? null;
        }
        try {
            $query = $this->pdo()->prepare('SELECT definition_revision,snapshot_json,values_json,created_at FROM booking_form_submissions WHERE booking_request_id=? LIMIT 1');
            $query->execute([(int) $requestId]);
            $row = $query->fetch();
        } catch (PDOException $error) {
            // Historical bookings remain readable until an existing site applies the new migration.
            if ((string) $error->getCode() === '42S02') return null;
            throw $error;
        }
        if (!is_array($row)) return null;
        return ['definitionRevision' => $row['definition_revision'], 'fields' => json_decode($row['snapshot_json'], true) ?: [],
            'values' => json_decode($row['values_json'], true) ?: [], 'submittedAt' => $row['created_at']];
    }

    private function assertBookingFormStorageReady(): void
    {
        if ($this->mode === 'file') return;
        try {
            $this->pdo()->query('SELECT booking_request_id FROM booking_form_submissions LIMIT 0');
        } catch (PDOException $error) {
            if ((string) $error->getCode() === '42S02') {
                throw new RuntimeException('The booking form database update has not been applied. The owner can apply the pending site migrations without reinstalling.');
            }
            throw $error;
        }
    }

    private function saveBookingFormSubmission(string|int $requestId, array $submission): void
    {
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $data['bookingFormSubmissions'][(string) $requestId] = $submission;
            $this->writeFileStore($data);
            return;
        }
        $query = $this->pdo()->prepare('INSERT INTO booking_form_submissions (booking_request_id,definition_revision,snapshot_json,values_json,created_at) VALUES (?,?,?,?,NOW())');
        $query->execute([(int) $requestId, $submission['definitionRevision'],
            json_encode($submission['fields'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            json_encode($submission['values'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
    }
}
