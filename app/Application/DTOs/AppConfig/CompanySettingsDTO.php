<?php

namespace App\Application\DTOs\AppConfig;

final class CompanySettingsDTO
{
    public function __construct(
        public readonly ?int $id,
        public readonly ?int $company_id,
        public readonly ?string $address,
        public readonly ?string $phone,
        public readonly ?string $email,
        public readonly ?string $contact_email,
        public readonly ?string $website,
        public readonly ?string $logo_path,
        public readonly ?string $cert_path,
        public readonly ?string $cert_password_enc,
        public readonly ?string $bank_accounts,
        public readonly ?string $extra_data,
        public readonly ?string $created_at,
        public readonly ?string $updated_at
    ) {
    }

    public static function fromRow(object $row): self
    {
        return new self(
            id: isset($row->id) ? (int) $row->id : null,
            company_id: isset($row->company_id) ? (int) $row->company_id : null,
            address: isset($row->address) ? (string) $row->address : null,
            phone: isset($row->phone) ? (string) $row->phone : null,
            email: isset($row->email) ? (string) $row->email : null,
            contact_email: isset($row->contact_email) ? (string) $row->contact_email : null,
            website: isset($row->website) ? (string) $row->website : null,
            logo_path: isset($row->logo_path) ? (string) $row->logo_path : null,
            cert_path: isset($row->cert_path) ? (string) $row->cert_path : null,
            cert_password_enc: isset($row->cert_password_enc) ? (string) $row->cert_password_enc : null,
            bank_accounts: isset($row->bank_accounts) ? (string) $row->bank_accounts : null,
            extra_data: isset($row->extra_data) ? (string) $row->extra_data : null,
            created_at: isset($row->created_at) ? (string) $row->created_at : null,
            updated_at: isset($row->updated_at) ? (string) $row->updated_at : null,
        );
    }
}
