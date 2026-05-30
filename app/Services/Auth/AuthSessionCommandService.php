<?php

namespace App\Services\Auth;

use App\Domain\Auth\Repositories\AuthSessionRepositoryInterface;
use Illuminate\Support\Facades\Cache;

class AuthSessionCommandService
{
    private ?bool $refreshTokensHasDeviceId = null;
    private ?bool $refreshTokensHasDeviceName = null;

    public function __construct(
        private AuthSessionRepositoryInterface $repository
    ) {
    }

    public function revokeActiveRefreshTokensForDevice(int $userId, string $deviceHashPrefix): void
    {
        foreach ($this->repository->listActiveRefreshSessionIdsForDevice($userId, $deviceHashPrefix) as $sessionId) {
            $this->forgetAuthenticatedSessionCache($userId, (int) $sessionId);
        }

        $this->repository->revokeActiveRefreshTokensForDevice($userId, $deviceHashPrefix);
    }

    public function createRefreshSession(
        int $userId,
        string $tokenHash,
        $expiresAt,
        string $deviceId,
        ?string $deviceName
    ): int {
        return $this->repository->insertRefreshToken(
            $this->buildRefreshTokenInsertPayload($userId, $tokenHash, $expiresAt, $deviceId, $deviceName)
        );
    }

    public function touchUserLastLogin(int $userId): void
    {
        $this->repository->touchUserLastLogin($userId);
    }

    public function rotateRefreshSession(
        int $sessionId,
        int $userId,
        string $newRefreshHash,
        $refreshExpiresAt,
        string $deviceId,
        ?string $deviceName
    ): int {
        return $this->repository->rotateRefreshSession(
            $sessionId,
            $userId,
            $newRefreshHash,
            $refreshExpiresAt,
            $deviceId,
            $deviceName
        );
    }

    public function revokeRefreshSession(int $sessionId): void
    {
        $session = $this->repository->findRefreshSessionById($sessionId);
        if ($session) {
            $this->forgetAuthenticatedSessionCache((int) $session->user_id, $sessionId);
        }

        $this->repository->revokeRefreshSession($sessionId);
    }

    private function forgetAuthenticatedSessionCache(int $userId, int $sessionId): void
    {
        $key = $this->authenticatedSessionCacheKey($userId, $sessionId);

        try {
            Cache::store('redis')->forget($key);
        } catch (\Throwable $e) {
            Cache::forget($key);
        }
    }

    private function authenticatedSessionCacheKey(int $userId, int $sessionId): string
    {
        return 'auth:session-context:' . $userId . ':' . $sessionId;
    }

    private function buildRefreshTokenInsertPayload(
        int $userId,
        string $tokenHash,
        $expiresAt,
        string $deviceId,
        ?string $deviceName
    ): array {
        $payload = [
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'created_at' => now(),
        ];

        if ($this->refreshTokensHasDeviceId()) {
            $payload['device_id'] = $deviceId;
        }

        if ($this->refreshTokensHasDeviceName()) {
            $payload['device_name'] = $deviceName !== null ? trim((string) $deviceName) : null;
        }

        return $payload;
    }

    private function refreshTokensHasDeviceId(): bool
    {
        if ($this->refreshTokensHasDeviceId === null) {
            $this->refreshTokensHasDeviceId = $this->repository->columnExists('auth', 'refresh_tokens', 'device_id');
        }

        return $this->refreshTokensHasDeviceId;
    }

    private function refreshTokensHasDeviceName(): bool
    {
        if ($this->refreshTokensHasDeviceName === null) {
            $this->refreshTokensHasDeviceName = $this->repository->columnExists('auth', 'refresh_tokens', 'device_name');
        }

        return $this->refreshTokensHasDeviceName;
    }
}
