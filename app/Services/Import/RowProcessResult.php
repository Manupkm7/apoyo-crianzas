<?php

namespace App\Services\Import;

/**
 * Resultado inmutable de un intento de resolución de una fila de importación
 * de usuarios (ver UserImportRowProcessor).
 */
readonly class RowProcessResult
{
    private function __construct(
        public readonly bool $success,
        public readonly ?string $userId,
        public readonly ?string $dniHash,
        public readonly ?string $role,
        public readonly ?string $reviewReason,
        public readonly ?string $notes,
    ) {}

    public static function created(string $userId, string $dniHash, string $role): self
    {
        return new self(true, $userId, $dniHash, $role, null, null);
    }

    public static function needsReview(string $reason, string $notes, ?string $dniHash = null, ?string $role = null): self
    {
        return new self(false, null, $dniHash, $role, $reason, $notes);
    }

    public function toStatus(): string
    {
        return $this->success ? 'created' : 'needs_review';
    }
}
