<?php

// Feature codes are now driven from appcfg.feature_labels (DB).
// This array is kept as emergency fallback only for environments
// where the migration has not run yet.
return [
    'commerce_feature_codes' => [],

    // These feature codes govern structural business flows and may ONLY be
    // modified by platform-level superadmins (SUPERADMIN / SUPER_ADMIN role).
    // Regular company admins (ADMIN / ADMINISTRADOR) are rejected at the API
    // layer even if they attempt a direct call.  The POS frontend also hides
    // the toggle UI for these codes (adminManagedFeatureCodes list).
    'superadmin_only_feature_codes' => [
        'SALES_SELLER_TO_CASHIER',
        'SALES_DETRACCION_ENABLED',
        'SALES_PERCEPCION_ENABLED',
        'SALES_RETENCION_ENABLED',
        'SALES_ANTICIPO_ENABLED',
        'SALES_ALLOW_ISSUED_EDIT_BEFORE_SUNAT_FINAL',
        'PURCHASES_DETRACCION_ENABLED',
        'PURCHASES_PERCEPCION_ENABLED',
        'PURCHASES_RETENCION_COMPRADOR_ENABLED',
        'PURCHASES_RETENCION_PROVEEDOR_ENABLED',
    ],
];
