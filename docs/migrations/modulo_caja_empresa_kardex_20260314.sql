-- ============================================================
-- Migration: Modulo Caja, Perfil Empresa, Kardex (indices)
-- Date: 2026-03-14
-- Run order: after base schema is deployed
-- ============================================================

-- ============================================================
-- 1. CAJA - Sesiones de caja
-- ============================================================
CREATE TABLE IF NOT EXISTS sales.cash_sessions (
    id            BIGSERIAL PRIMARY KEY,
    company_id    INT           NOT NULL,
    branch_id     INT,
    cash_register_id INT        NOT NULL,
    user_id       INT           NOT NULL,
    opened_at     TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    closed_at     TIMESTAMPTZ,
    opening_balance  NUMERIC(15,4) NOT NULL DEFAULT 0,
    closing_balance  NUMERIC(15,4),
    expected_balance NUMERIC(15,4) NOT NULL DEFAULT 0,
    status        VARCHAR(20)   NOT NULL DEFAULT 'OPEN',  -- OPEN | CLOSED
    notes         TEXT,
    created_at    TIMESTAMPTZ   NOT NULL DEFAULT NOW()
);

ALTER TABLE sales.cash_sessions
    ADD COLUMN IF NOT EXISTS company_id BIGINT,
    ADD COLUMN IF NOT EXISTS branch_id BIGINT,
    ADD COLUMN IF NOT EXISTS cash_register_id BIGINT,
    ADD COLUMN IF NOT EXISTS user_id BIGINT,
    ADD COLUMN IF NOT EXISTS opened_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS closed_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS opening_balance NUMERIC(15,4) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS closing_balance NUMERIC(15,4),
    ADD COLUMN IF NOT EXISTS expected_balance NUMERIC(15,4) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS status VARCHAR(20) NOT NULL DEFAULT 'OPEN',
    ADD COLUMN IF NOT EXISTS notes TEXT,
    ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ NOT NULL DEFAULT NOW();

UPDATE sales.cash_sessions
SET user_id = COALESCE(user_id, opened_by),
    created_at = COALESCE(created_at, opened_at, NOW())
WHERE user_id IS NULL
   OR created_at IS NULL;

COMMENT ON TABLE sales.cash_sessions IS 'Apertura y cierre de caja por caja registradora';
COMMENT ON COLUMN sales.cash_sessions.expected_balance IS 'Saldo calculado: saldo_apertura + ingresos - egresos';
COMMENT ON COLUMN sales.cash_sessions.closing_balance  IS 'Saldo fisico contado al cierre';

CREATE INDEX IF NOT EXISTS idx_cash_sessions_company
    ON sales.cash_sessions(company_id, status);
CREATE INDEX IF NOT EXISTS idx_cash_sessions_register
    ON sales.cash_sessions(cash_register_id, status);

-- ============================================================
-- 2. CAJA - Movimientos de caja
-- ============================================================
CREATE TABLE IF NOT EXISTS sales.cash_movements (
    id               BIGSERIAL PRIMARY KEY,
    company_id       INT          NOT NULL,
    branch_id        INT,
    cash_register_id INT          NOT NULL,
    cash_session_id  BIGINT,
    movement_type    VARCHAR(20)  NOT NULL,  -- IN | OUT
    amount           NUMERIC(15,4) NOT NULL,
    description      TEXT,
    ref_type         VARCHAR(50),            -- MANUAL | COMMERCIAL_DOCUMENT | ADJUSTMENT
    ref_id           BIGINT,
    user_id          INT          NOT NULL,
    movement_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    created_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

ALTER TABLE sales.cash_movements
    ADD COLUMN IF NOT EXISTS company_id BIGINT,
    ADD COLUMN IF NOT EXISTS branch_id BIGINT,
    ADD COLUMN IF NOT EXISTS cash_register_id BIGINT,
    ADD COLUMN IF NOT EXISTS cash_session_id BIGINT,
    ADD COLUMN IF NOT EXISTS movement_type VARCHAR(20),
    ADD COLUMN IF NOT EXISTS amount NUMERIC(15,4) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS description TEXT,
    ADD COLUMN IF NOT EXISTS ref_type VARCHAR(50),
    ADD COLUMN IF NOT EXISTS ref_id BIGINT,
    ADD COLUMN IF NOT EXISTS user_id BIGINT,
    ADD COLUMN IF NOT EXISTS movement_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ NOT NULL DEFAULT NOW();

UPDATE sales.cash_movements cm
SET company_id = COALESCE(cm.company_id, cs.company_id),
    branch_id = COALESCE(cm.branch_id, cs.branch_id),
    cash_register_id = COALESCE(cm.cash_register_id, cs.cash_register_id),
    description = COALESCE(cm.description, cm.notes),
    user_id = COALESCE(cm.user_id, cm.created_by, cs.user_id),
    movement_at = COALESCE(cm.movement_at, cm.created_at, NOW())
FROM sales.cash_sessions cs
WHERE cm.cash_session_id = cs.id
  AND (
      cm.company_id IS NULL
      OR cm.branch_id IS NULL
      OR cm.cash_register_id IS NULL
      OR cm.description IS NULL
      OR cm.user_id IS NULL
      OR cm.movement_at IS NULL
  );

UPDATE sales.cash_movements
SET description = COALESCE(description, notes),
    user_id = COALESCE(user_id, created_by),
    movement_at = COALESCE(movement_at, created_at, NOW())
WHERE description IS NULL
   OR user_id IS NULL
   OR movement_at IS NULL;

COMMENT ON TABLE sales.cash_movements IS 'Ingresos y egresos de caja (manuales y automaticos)';

CREATE INDEX IF NOT EXISTS idx_cash_movements_session
    ON sales.cash_movements(cash_session_id);
CREATE INDEX IF NOT EXISTS idx_cash_movements_register
    ON sales.cash_movements(cash_register_id, movement_at DESC);
CREATE INDEX IF NOT EXISTS idx_cash_movements_company
    ON sales.cash_movements(company_id, movement_at DESC);

-- ============================================================
-- 3. EMPRESA - Configuracion extendida
-- ============================================================
CREATE TABLE IF NOT EXISTS core.company_settings (
    company_id          INT          PRIMARY KEY,
    address             TEXT,
    phone               VARCHAR(60),
    email               VARCHAR(200),
    website             VARCHAR(300),
    logo_path           VARCHAR(500),    -- ruta en disco local (public disk)
    cert_path           VARCHAR(500),    -- ruta en disco LOCAL (nunca publico)
    cert_password_enc   TEXT,            -- password cifrado con APP_KEY (Crypt::encryptString)
    bank_accounts       JSONB            NOT NULL DEFAULT '[]'::JSONB,
    extra_data          JSONB            NOT NULL DEFAULT '{}'::JSONB,
    updated_at          TIMESTAMPTZ      NOT NULL DEFAULT NOW()
);

COMMENT ON TABLE core.company_settings IS 'Configuracion extendida de empresa: contacto, logo, certificado digital, cuentas bancarias';
COMMENT ON COLUMN core.company_settings.cert_path          IS 'Ruta del certificado .p12/.pfx en disco privado (no publico)';
COMMENT ON COLUMN core.company_settings.cert_password_enc  IS 'Password del certificado cifrado con Illuminate\Support\Facades\Crypt';

-- ============================================================
-- 4. KARDEX - Indices de rendimiento (tabla ya debe existir)
-- ============================================================
CREATE INDEX IF NOT EXISTS idx_inv_ledger_product_date
    ON inventory.inventory_ledger(company_id, product_id, moved_at DESC);
CREATE INDEX IF NOT EXISTS idx_inv_ledger_warehouse_date
    ON inventory.inventory_ledger(company_id, warehouse_id, moved_at DESC);
CREATE INDEX IF NOT EXISTS idx_inv_ledger_ref
    ON inventory.inventory_ledger(ref_type, ref_id);
