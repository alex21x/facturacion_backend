<?php

namespace App\Services\AppConfig;

use App\Infrastructure\Repositories\AppConfig\BackupMaintenanceRepository;

class BackupMaintenanceService
{
    public function __construct(private BackupMaintenanceRepository $repository)
    {
    }

    public function findCompanyById(int $companyId): ?object
    {
        return $this->repository->findCompanyById($companyId);
    }

    public function runRestoreTransaction(string $sql, callable $afterRestore): void
    {
        $this->repository->beginTransaction();

        try {
            $this->repository->executeUnprepared($sql);
            $afterRestore();
            $this->repository->commit();
        } catch (\Throwable $e) {
            $this->repository->rollBack();
            throw $e;
        }
    }

    public function getPdo(): \PDO
    {
        return $this->repository->getPdo();
    }

    public function cursorCompanyTableRows(string $schema, string $table, int $companyId): iterable
    {
        return $this->repository->cursorCompanyTableRows($schema, $table, $companyId);
    }

    public function cursorRawSelect(string $sql): iterable
    {
        return $this->repository->cursorRawSelect($sql);
    }

    public function fetchCompanyScopedTableRows(): array
    {
        return $this->repository->fetchCompanyScopedTableRows();
    }

    public function fetchForeignKeyTableRows(): array
    {
        return $this->repository->fetchForeignKeyTableRows();
    }

    public function fetchForeignKeyColumnRows(): array
    {
        return $this->repository->fetchForeignKeyColumnRows();
    }
}
