--
-- PostgreSQL database dump
--

\restrict Q8excKmk7D3fluEXNfdQNnxQydW5s8QMTDcIj8HcqufPqsq1LjSbDaSYecM7NoY

-- Dumped from database version 18.3
-- Dumped by pg_dump version 18.3

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: appcfg; Type: SCHEMA; Schema: -; Owner: postgres
--

CREATE SCHEMA appcfg;


ALTER SCHEMA appcfg OWNER TO postgres;

--
-- Name: auth; Type: SCHEMA; Schema: -; Owner: postgres
--

CREATE SCHEMA auth;


ALTER SCHEMA auth OWNER TO postgres;

--
-- Name: billing; Type: SCHEMA; Schema: -; Owner: postgres
--

CREATE SCHEMA billing;


ALTER SCHEMA billing OWNER TO postgres;

--
-- Name: core; Type: SCHEMA; Schema: -; Owner: postgres
--

CREATE SCHEMA core;


ALTER SCHEMA core OWNER TO postgres;

--
-- Name: inventory; Type: SCHEMA; Schema: -; Owner: postgres
--

CREATE SCHEMA inventory;


ALTER SCHEMA inventory OWNER TO postgres;

--
-- Name: master; Type: SCHEMA; Schema: -; Owner: postgres
--

CREATE SCHEMA master;


ALTER SCHEMA master OWNER TO postgres;

--
-- Name: sales; Type: SCHEMA; Schema: -; Owner: postgres
--

CREATE SCHEMA sales;


ALTER SCHEMA sales OWNER TO postgres;

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: branch_feature_toggles; Type: TABLE; Schema: appcfg; Owner: postgres
--

CREATE TABLE appcfg.branch_feature_toggles (
    company_id bigint NOT NULL,
    branch_id bigint NOT NULL,
    feature_code character varying(80) NOT NULL,
    is_enabled boolean DEFAULT false NOT NULL,
    config jsonb,
    updated_by bigint,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE appcfg.branch_feature_toggles OWNER TO postgres;

--
-- Name: branch_modules; Type: TABLE; Schema: appcfg; Owner: postgres
--

CREATE TABLE appcfg.branch_modules (
    company_id bigint NOT NULL,
    branch_id bigint NOT NULL,
    module_id bigint NOT NULL,
    is_enabled boolean DEFAULT true NOT NULL,
    config jsonb,
    updated_by bigint,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE appcfg.branch_modules OWNER TO postgres;

--
-- Name: company_feature_toggles; Type: TABLE; Schema: appcfg; Owner: postgres
--

CREATE TABLE appcfg.company_feature_toggles (
    company_id bigint NOT NULL,
    feature_code character varying(80) NOT NULL,
    is_enabled boolean DEFAULT false NOT NULL,
    config jsonb,
    updated_by bigint,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE appcfg.company_feature_toggles OWNER TO postgres;

--
-- Name: company_modules; Type: TABLE; Schema: appcfg; Owner: postgres
--

CREATE TABLE appcfg.company_modules (
    company_id bigint NOT NULL,
    module_id bigint NOT NULL,
    is_enabled boolean DEFAULT true NOT NULL,
    is_mandatory boolean DEFAULT false NOT NULL,
    config jsonb,
    updated_by bigint,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE appcfg.company_modules OWNER TO postgres;

--
-- Name: company_role_profiles; Type: TABLE; Schema: appcfg; Owner: postgres
--

CREATE TABLE appcfg.company_role_profiles (
    company_id bigint NOT NULL,
    role_id bigint NOT NULL,
    functional_profile character varying(20),
    updated_by bigint,
    updated_at timestamp without time zone
);


ALTER TABLE appcfg.company_role_profiles OWNER TO postgres;

--
-- Name: company_ui_field_settings; Type: TABLE; Schema: appcfg; Owner: postgres
--

CREATE TABLE appcfg.company_ui_field_settings (
    company_id bigint NOT NULL,
    field_id bigint NOT NULL,
    is_visible boolean,
    is_required boolean,
    is_editable boolean,
    show_in_list boolean,
    show_in_form boolean,
    show_in_filters boolean,
    updated_by bigint,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE appcfg.company_ui_field_settings OWNER TO postgres;

--
-- Name: company_units; Type: TABLE; Schema: appcfg; Owner: postgres
--

CREATE TABLE appcfg.company_units (
    company_id bigint NOT NULL,
    unit_id bigint NOT NULL,
    is_enabled boolean DEFAULT false NOT NULL,
    updated_by bigint,
    updated_at timestamp without time zone
);


ALTER TABLE appcfg.company_units OWNER TO postgres;

--
-- Name: modules; Type: TABLE; Schema: appcfg; Owner: postgres
--

CREATE TABLE appcfg.modules (
    id bigint NOT NULL,
    code character varying(60) NOT NULL,
    name character varying(120) NOT NULL,
    description character varying(250),
    is_core boolean DEFAULT false NOT NULL,
    status smallint DEFAULT 1 NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE appcfg.modules OWNER TO postgres;

--
-- Name: modules_id_seq; Type: SEQUENCE; Schema: appcfg; Owner: postgres
--

CREATE SEQUENCE appcfg.modules_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE appcfg.modules_id_seq OWNER TO postgres;

--
-- Name: modules_id_seq; Type: SEQUENCE OWNED BY; Schema: appcfg; Owner: postgres
--

ALTER SEQUENCE appcfg.modules_id_seq OWNED BY appcfg.modules.id;


--
-- Name: saved_filters; Type: TABLE; Schema: appcfg; Owner: postgres
--

CREATE TABLE appcfg.saved_filters (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    user_id bigint,
    entity_id bigint NOT NULL,
    name character varying(120) NOT NULL,
    is_public boolean DEFAULT false NOT NULL,
    filter_payload jsonb NOT NULL,
    sort_payload jsonb,
    status smallint DEFAULT 1 NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE appcfg.saved_filters OWNER TO postgres;

--
-- Name: saved_filters_id_seq; Type: SEQUENCE; Schema: appcfg; Owner: postgres
--

CREATE SEQUENCE appcfg.saved_filters_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE appcfg.saved_filters_id_seq OWNER TO postgres;

--
-- Name: saved_filters_id_seq; Type: SEQUENCE OWNED BY; Schema: appcfg; Owner: postgres
--

ALTER SEQUENCE appcfg.saved_filters_id_seq OWNED BY appcfg.saved_filters.id;


--
-- Name: ui_entities; Type: TABLE; Schema: appcfg; Owner: postgres
--

CREATE TABLE appcfg.ui_entities (
    id bigint NOT NULL,
    module_id bigint NOT NULL,
    code character varying(80) NOT NULL,
    name character varying(120) NOT NULL,
    route_path character varying(180),
    status smallint DEFAULT 1 NOT NULL
);


ALTER TABLE appcfg.ui_entities OWNER TO postgres;

--
-- Name: ui_entities_id_seq; Type: SEQUENCE; Schema: appcfg; Owner: postgres
--

CREATE SEQUENCE appcfg.ui_entities_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE appcfg.ui_entities_id_seq OWNER TO postgres;

--
-- Name: ui_entities_id_seq; Type: SEQUENCE OWNED BY; Schema: appcfg; Owner: postgres
--

ALTER SEQUENCE appcfg.ui_entities_id_seq OWNED BY appcfg.ui_entities.id;


--
-- Name: ui_fields; Type: TABLE; Schema: appcfg; Owner: postgres
--

CREATE TABLE appcfg.ui_fields (
    id bigint NOT NULL,
    entity_id bigint NOT NULL,
    code character varying(80) NOT NULL,
    label character varying(120) NOT NULL,
    data_type character varying(40) NOT NULL,
    default_visible boolean DEFAULT true NOT NULL,
    default_editable boolean DEFAULT true NOT NULL,
    default_filterable boolean DEFAULT false NOT NULL,
    display_order integer DEFAULT 0 NOT NULL,
    config jsonb,
    status smallint DEFAULT 1 NOT NULL
);


ALTER TABLE appcfg.ui_fields OWNER TO postgres;

--
-- Name: ui_fields_id_seq; Type: SEQUENCE; Schema: appcfg; Owner: postgres
--

CREATE SEQUENCE appcfg.ui_fields_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE appcfg.ui_fields_id_seq OWNER TO postgres;

--
-- Name: ui_fields_id_seq; Type: SEQUENCE OWNED BY; Schema: appcfg; Owner: postgres
--

ALTER SEQUENCE appcfg.ui_fields_id_seq OWNED BY appcfg.ui_fields.id;


--
-- Name: permissions; Type: TABLE; Schema: auth; Owner: postgres
--

CREATE TABLE auth.permissions (
    id bigint NOT NULL,
    code character varying(100) NOT NULL,
    description character varying(200) NOT NULL
);


ALTER TABLE auth.permissions OWNER TO postgres;

--
-- Name: permissions_id_seq; Type: SEQUENCE; Schema: auth; Owner: postgres
--

CREATE SEQUENCE auth.permissions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE auth.permissions_id_seq OWNER TO postgres;

--
-- Name: permissions_id_seq; Type: SEQUENCE OWNED BY; Schema: auth; Owner: postgres
--

ALTER SEQUENCE auth.permissions_id_seq OWNED BY auth.permissions.id;


--
-- Name: refresh_tokens; Type: TABLE; Schema: auth; Owner: postgres
--

CREATE TABLE auth.refresh_tokens (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    token_hash character varying(255) NOT NULL,
    expires_at timestamp with time zone NOT NULL,
    revoked_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE auth.refresh_tokens OWNER TO postgres;

--
-- Name: refresh_tokens_id_seq; Type: SEQUENCE; Schema: auth; Owner: postgres
--

CREATE SEQUENCE auth.refresh_tokens_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE auth.refresh_tokens_id_seq OWNER TO postgres;

--
-- Name: refresh_tokens_id_seq; Type: SEQUENCE OWNED BY; Schema: auth; Owner: postgres
--

ALTER SEQUENCE auth.refresh_tokens_id_seq OWNED BY auth.refresh_tokens.id;


--
-- Name: role_module_access; Type: TABLE; Schema: auth; Owner: postgres
--

CREATE TABLE auth.role_module_access (
    role_id bigint NOT NULL,
    module_id bigint NOT NULL,
    can_view boolean DEFAULT true NOT NULL,
    can_create boolean DEFAULT false NOT NULL,
    can_update boolean DEFAULT false NOT NULL,
    can_delete boolean DEFAULT false NOT NULL,
    can_export boolean DEFAULT false NOT NULL,
    can_approve boolean DEFAULT false NOT NULL,
    field_rules jsonb,
    data_scope_rules jsonb,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE auth.role_module_access OWNER TO postgres;

--
-- Name: role_permissions; Type: TABLE; Schema: auth; Owner: postgres
--

CREATE TABLE auth.role_permissions (
    role_id bigint NOT NULL,
    permission_id bigint NOT NULL
);


ALTER TABLE auth.role_permissions OWNER TO postgres;

--
-- Name: role_ui_field_access; Type: TABLE; Schema: auth; Owner: postgres
--

CREATE TABLE auth.role_ui_field_access (
    role_id bigint NOT NULL,
    field_id bigint NOT NULL,
    can_view boolean,
    can_edit boolean,
    can_filter boolean
);


ALTER TABLE auth.role_ui_field_access OWNER TO postgres;

--
-- Name: roles; Type: TABLE; Schema: auth; Owner: postgres
--

CREATE TABLE auth.roles (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    code character varying(50) NOT NULL,
    name character varying(100) NOT NULL,
    status smallint DEFAULT 1 NOT NULL
);


ALTER TABLE auth.roles OWNER TO postgres;

--
-- Name: roles_id_seq; Type: SEQUENCE; Schema: auth; Owner: postgres
--

CREATE SEQUENCE auth.roles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE auth.roles_id_seq OWNER TO postgres;

--
-- Name: roles_id_seq; Type: SEQUENCE OWNED BY; Schema: auth; Owner: postgres
--

ALTER SEQUENCE auth.roles_id_seq OWNED BY auth.roles.id;


--
-- Name: user_module_overrides; Type: TABLE; Schema: auth; Owner: postgres
--

CREATE TABLE auth.user_module_overrides (
    user_id bigint NOT NULL,
    module_id bigint NOT NULL,
    can_view boolean,
    can_create boolean,
    can_update boolean,
    can_delete boolean,
    can_export boolean,
    can_approve boolean,
    field_rules jsonb,
    data_scope_rules jsonb,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE auth.user_module_overrides OWNER TO postgres;

--
-- Name: user_roles; Type: TABLE; Schema: auth; Owner: postgres
--

CREATE TABLE auth.user_roles (
    user_id bigint NOT NULL,
    role_id bigint NOT NULL
);


ALTER TABLE auth.user_roles OWNER TO postgres;

--
-- Name: user_ui_field_access; Type: TABLE; Schema: auth; Owner: postgres
--

CREATE TABLE auth.user_ui_field_access (
    user_id bigint NOT NULL,
    field_id bigint NOT NULL,
    can_view boolean,
    can_edit boolean,
    can_filter boolean
);


ALTER TABLE auth.user_ui_field_access OWNER TO postgres;

--
-- Name: users; Type: TABLE; Schema: auth; Owner: postgres
--

CREATE TABLE auth.users (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    branch_id bigint,
    username character varying(80) NOT NULL,
    password_hash character varying(255) NOT NULL,
    first_name character varying(100) NOT NULL,
    last_name character varying(100),
    email character varying(150),
    phone character varying(50),
    status smallint DEFAULT 1 NOT NULL,
    last_login_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    deleted_at timestamp with time zone
);


ALTER TABLE auth.users OWNER TO postgres;

--
-- Name: users_id_seq; Type: SEQUENCE; Schema: auth; Owner: postgres
--

CREATE SEQUENCE auth.users_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE auth.users_id_seq OWNER TO postgres;

--
-- Name: users_id_seq; Type: SEQUENCE OWNED BY; Schema: auth; Owner: postgres
--

ALTER SEQUENCE auth.users_id_seq OWNED BY auth.users.id;


--
-- Name: documents; Type: TABLE; Schema: billing; Owner: postgres
--

CREATE TABLE billing.documents (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    source_order_id bigint,
    doc_type character varying(20) NOT NULL,
    series character varying(10) NOT NULL,
    number bigint NOT NULL,
    issue_at timestamp with time zone NOT NULL,
    customer_id bigint NOT NULL,
    currency_id bigint NOT NULL,
    subtotal numeric(14,2) NOT NULL,
    tax_total numeric(14,2) NOT NULL,
    total numeric(14,2) NOT NULL,
    status character varying(20) DEFAULT 'DRAFT'::character varying NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE billing.documents OWNER TO postgres;

--
-- Name: documents_id_seq; Type: SEQUENCE; Schema: billing; Owner: postgres
--

CREATE SEQUENCE billing.documents_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE billing.documents_id_seq OWNER TO postgres;

--
-- Name: documents_id_seq; Type: SEQUENCE OWNED BY; Schema: billing; Owner: postgres
--

ALTER SEQUENCE billing.documents_id_seq OWNED BY billing.documents.id;


--
-- Name: branches; Type: TABLE; Schema: core; Owner: postgres
--

CREATE TABLE core.branches (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    code character varying(20) NOT NULL,
    name character varying(120) NOT NULL,
    address character varying(250),
    is_main boolean DEFAULT false NOT NULL,
    status smallint DEFAULT 1 NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE core.branches OWNER TO postgres;

--
-- Name: branches_id_seq; Type: SEQUENCE; Schema: core; Owner: postgres
--

CREATE SEQUENCE core.branches_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE core.branches_id_seq OWNER TO postgres;

--
-- Name: branches_id_seq; Type: SEQUENCE OWNED BY; Schema: core; Owner: postgres
--

ALTER SEQUENCE core.branches_id_seq OWNED BY core.branches.id;


--
-- Name: companies; Type: TABLE; Schema: core; Owner: postgres
--

CREATE TABLE core.companies (
    id bigint NOT NULL,
    tax_id character varying(20),
    legal_name character varying(200) NOT NULL,
    trade_name character varying(200),
    email character varying(150),
    phone character varying(50),
    address character varying(250),
    status smallint DEFAULT 1 NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE core.companies OWNER TO postgres;

--
-- Name: companies_id_seq; Type: SEQUENCE; Schema: core; Owner: postgres
--

CREATE SEQUENCE core.companies_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE core.companies_id_seq OWNER TO postgres;

--
-- Name: companies_id_seq; Type: SEQUENCE OWNED BY; Schema: core; Owner: postgres
--

ALTER SEQUENCE core.companies_id_seq OWNED BY core.companies.id;


--
-- Name: company_igv_rates; Type: TABLE; Schema: core; Owner: postgres
--

CREATE TABLE core.company_igv_rates (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    name character varying(120) NOT NULL,
    rate_percent numeric(8,4) NOT NULL,
    is_active boolean DEFAULT false NOT NULL,
    effective_from date,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE core.company_igv_rates OWNER TO postgres;

--
-- Name: company_igv_rates_id_seq; Type: SEQUENCE; Schema: core; Owner: postgres
--

CREATE SEQUENCE core.company_igv_rates_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE core.company_igv_rates_id_seq OWNER TO postgres;

--
-- Name: company_igv_rates_id_seq; Type: SEQUENCE OWNED BY; Schema: core; Owner: postgres
--

ALTER SEQUENCE core.company_igv_rates_id_seq OWNED BY core.company_igv_rates.id;


--
-- Name: company_settings; Type: TABLE; Schema: core; Owner: postgres
--

CREATE TABLE core.company_settings (
    company_id integer NOT NULL,
    address text,
    phone character varying(60),
    email character varying(200),
    website character varying(300),
    logo_path character varying(500),
    cert_path character varying(500),
    cert_password_enc text,
    bank_accounts jsonb DEFAULT '[]'::jsonb NOT NULL,
    extra_data jsonb DEFAULT '{}'::jsonb NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE core.company_settings OWNER TO postgres;

--
-- Name: TABLE company_settings; Type: COMMENT; Schema: core; Owner: postgres
--

COMMENT ON TABLE core.company_settings IS 'Configuracion extendida de empresa: contacto, logo, certificado digital, cuentas bancarias';


--
-- Name: COLUMN company_settings.cert_path; Type: COMMENT; Schema: core; Owner: postgres
--

COMMENT ON COLUMN core.company_settings.cert_path IS 'Ruta del certificado .p12/.pfx en disco privado (no publico)';


--
-- Name: COLUMN company_settings.cert_password_enc; Type: COMMENT; Schema: core; Owner: postgres
--

COMMENT ON COLUMN core.company_settings.cert_password_enc IS 'Password del certificado cifrado con Illuminate\Support\Facades\Crypt';


--
-- Name: currencies; Type: TABLE; Schema: core; Owner: postgres
--

CREATE TABLE core.currencies (
    id bigint NOT NULL,
    code character varying(10) NOT NULL,
    name character varying(50) NOT NULL,
    symbol character varying(10) NOT NULL,
    is_default boolean DEFAULT false NOT NULL,
    status smallint DEFAULT 1 NOT NULL
);


ALTER TABLE core.currencies OWNER TO postgres;

--
-- Name: currencies_id_seq; Type: SEQUENCE; Schema: core; Owner: postgres
--

CREATE SEQUENCE core.currencies_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE core.currencies_id_seq OWNER TO postgres;

--
-- Name: currencies_id_seq; Type: SEQUENCE OWNED BY; Schema: core; Owner: postgres
--

ALTER SEQUENCE core.currencies_id_seq OWNED BY core.currencies.id;


--
-- Name: payment_methods; Type: TABLE; Schema: core; Owner: postgres
--

CREATE TABLE core.payment_methods (
    id bigint NOT NULL,
    code character varying(20) NOT NULL,
    name character varying(100) NOT NULL,
    status smallint DEFAULT 1 NOT NULL
);


ALTER TABLE core.payment_methods OWNER TO postgres;

--
-- Name: payment_methods_id_seq; Type: SEQUENCE; Schema: core; Owner: postgres
--

CREATE SEQUENCE core.payment_methods_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE core.payment_methods_id_seq OWNER TO postgres;

--
-- Name: payment_methods_id_seq; Type: SEQUENCE OWNED BY; Schema: core; Owner: postgres
--

ALTER SEQUENCE core.payment_methods_id_seq OWNED BY core.payment_methods.id;


--
-- Name: tax_categories; Type: TABLE; Schema: core; Owner: postgres
--

CREATE TABLE core.tax_categories (
    id bigint NOT NULL,
    code character varying(10) NOT NULL,
    name character varying(120) NOT NULL,
    tax_tribute_code integer,
    rate_percent numeric(8,4) DEFAULT 0 NOT NULL,
    status smallint DEFAULT 1 NOT NULL,
    company_id bigint,
    created_at timestamp without time zone,
    updated_at timestamp without time zone
);


ALTER TABLE core.tax_categories OWNER TO postgres;

--
-- Name: units; Type: TABLE; Schema: core; Owner: postgres
--

CREATE TABLE core.units (
    id bigint NOT NULL,
    code character varying(20) NOT NULL,
    sunat_uom_code character varying(10),
    name character varying(80) NOT NULL,
    status smallint DEFAULT 1 NOT NULL
);


ALTER TABLE core.units OWNER TO postgres;

--
-- Name: units_id_seq; Type: SEQUENCE; Schema: core; Owner: postgres
--

CREATE SEQUENCE core.units_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE core.units_id_seq OWNER TO postgres;

--
-- Name: units_id_seq; Type: SEQUENCE OWNED BY; Schema: core; Owner: postgres
--

ALTER SEQUENCE core.units_id_seq OWNED BY core.units.id;


--
-- Name: categories; Type: TABLE; Schema: inventory; Owner: postgres
--

CREATE TABLE inventory.categories (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    name character varying(120) NOT NULL,
    status smallint DEFAULT 1 NOT NULL
);


ALTER TABLE inventory.categories OWNER TO postgres;

--
-- Name: categories_id_seq; Type: SEQUENCE; Schema: inventory; Owner: postgres
--

CREATE SEQUENCE inventory.categories_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE inventory.categories_id_seq OWNER TO postgres;

--
-- Name: categories_id_seq; Type: SEQUENCE OWNED BY; Schema: inventory; Owner: postgres
--

ALTER SEQUENCE inventory.categories_id_seq OWNED BY inventory.categories.id;


--
-- Name: inventory_ledger; Type: TABLE; Schema: inventory; Owner: postgres
--

CREATE TABLE inventory.inventory_ledger (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    warehouse_id bigint NOT NULL,
    product_id bigint NOT NULL,
    lot_id bigint,
    movement_type character varying(30) NOT NULL,
    quantity numeric(14,3) NOT NULL,
    unit_cost numeric(14,4),
    ref_type character varying(30),
    ref_id bigint,
    notes character varying(300),
    moved_at timestamp with time zone DEFAULT now() NOT NULL,
    created_by bigint,
    CONSTRAINT inventory_ledger_movement_type_check CHECK (((movement_type)::text = ANY (ARRAY[('IN'::character varying)::text, ('OUT'::character varying)::text, ('ADJUST'::character varying)::text]))),
    CONSTRAINT inventory_ledger_quantity_check CHECK ((quantity > (0)::numeric))
);


ALTER TABLE inventory.inventory_ledger OWNER TO postgres;

--
-- Name: current_stock; Type: VIEW; Schema: inventory; Owner: postgres
--

CREATE VIEW inventory.current_stock AS
 SELECT company_id,
    warehouse_id,
    product_id,
    COALESCE(sum(
        CASE movement_type
            WHEN 'IN'::text THEN quantity
            WHEN 'OUT'::text THEN (- quantity)
            WHEN 'ADJUST'::text THEN quantity
            ELSE (0)::numeric
        END), (0)::numeric) AS stock
   FROM inventory.inventory_ledger
  GROUP BY company_id, warehouse_id, product_id;


ALTER VIEW inventory.current_stock OWNER TO postgres;

--
-- Name: current_stock_by_lot; Type: VIEW; Schema: inventory; Owner: postgres
--

CREATE VIEW inventory.current_stock_by_lot AS
 SELECT company_id,
    warehouse_id,
    product_id,
    lot_id,
    COALESCE(sum(
        CASE movement_type
            WHEN 'IN'::text THEN quantity
            WHEN 'OUT'::text THEN (- quantity)
            WHEN 'ADJUST'::text THEN quantity
            ELSE (0)::numeric
        END), (0)::numeric) AS stock
   FROM inventory.inventory_ledger il
  WHERE (lot_id IS NOT NULL)
  GROUP BY company_id, warehouse_id, product_id, lot_id;


ALTER VIEW inventory.current_stock_by_lot OWNER TO postgres;

--
-- Name: inventory_ledger_id_seq; Type: SEQUENCE; Schema: inventory; Owner: postgres
--

CREATE SEQUENCE inventory.inventory_ledger_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE inventory.inventory_ledger_id_seq OWNER TO postgres;

--
-- Name: inventory_ledger_id_seq; Type: SEQUENCE OWNED BY; Schema: inventory; Owner: postgres
--

ALTER SEQUENCE inventory.inventory_ledger_id_seq OWNED BY inventory.inventory_ledger.id;


--
-- Name: inventory_settings; Type: TABLE; Schema: inventory; Owner: postgres
--

CREATE TABLE inventory.inventory_settings (
    company_id bigint NOT NULL,
    inventory_mode character varying(20) DEFAULT 'KARDEX_SIMPLE'::character varying NOT NULL,
    lot_outflow_strategy character varying(20) DEFAULT 'MANUAL'::character varying NOT NULL,
    allow_negative_stock boolean DEFAULT false NOT NULL,
    enforce_lot_for_tracked boolean DEFAULT true NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    complexity_mode character varying(20) DEFAULT 'BASIC'::character varying NOT NULL,
    enable_inventory_pro boolean DEFAULT false NOT NULL,
    enable_lot_tracking boolean DEFAULT false NOT NULL,
    enable_expiry_tracking boolean DEFAULT false NOT NULL,
    enable_advanced_reporting boolean DEFAULT false NOT NULL,
    enable_graphical_dashboard boolean DEFAULT false NOT NULL,
    enable_location_control boolean DEFAULT false NOT NULL,
    CONSTRAINT inventory_settings_inventory_mode_check CHECK (((inventory_mode)::text = ANY (ARRAY[('KARDEX_SIMPLE'::character varying)::text, ('LOT_TRACKING'::character varying)::text]))),
    CONSTRAINT inventory_settings_lot_outflow_strategy_check CHECK (((lot_outflow_strategy)::text = ANY (ARRAY[('MANUAL'::character varying)::text, ('FIFO'::character varying)::text, ('FEFO'::character varying)::text])))
);


ALTER TABLE inventory.inventory_settings OWNER TO postgres;

--
-- Name: lot_expiry_projection; Type: TABLE; Schema: inventory; Owner: postgres
--

CREATE TABLE inventory.lot_expiry_projection (
    company_id bigint NOT NULL,
    warehouse_id bigint NOT NULL,
    product_id bigint NOT NULL,
    lot_id bigint NOT NULL,
    branch_id bigint,
    lot_code character varying(80) NOT NULL,
    manufacture_at date,
    expires_at date,
    stock numeric(18,8) DEFAULT 0 NOT NULL,
    unit_cost numeric(18,8) DEFAULT 0 NOT NULL,
    stock_value numeric(18,8) DEFAULT 0 NOT NULL,
    expiry_bucket character varying(20),
    days_to_expire integer,
    updated_at timestamp with time zone
);


ALTER TABLE inventory.lot_expiry_projection OWNER TO postgres;

--
-- Name: outbox_events; Type: TABLE; Schema: inventory; Owner: postgres
--

CREATE TABLE inventory.outbox_events (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    aggregate_type character varying(80) NOT NULL,
    aggregate_id character varying(80) NOT NULL,
    event_type character varying(120) NOT NULL,
    payload_json jsonb NOT NULL,
    status character varying(20) DEFAULT 'PENDING'::character varying NOT NULL,
    attempts integer DEFAULT 0 NOT NULL,
    available_at timestamp with time zone NOT NULL,
    processed_at timestamp with time zone,
    last_error text,
    created_at timestamp with time zone,
    updated_at timestamp with time zone
);


ALTER TABLE inventory.outbox_events OWNER TO postgres;

--
-- Name: outbox_events_id_seq; Type: SEQUENCE; Schema: inventory; Owner: postgres
--

CREATE SEQUENCE inventory.outbox_events_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE inventory.outbox_events_id_seq OWNER TO postgres;

--
-- Name: outbox_events_id_seq; Type: SEQUENCE OWNED BY; Schema: inventory; Owner: postgres
--

ALTER SEQUENCE inventory.outbox_events_id_seq OWNED BY inventory.outbox_events.id;


--
-- Name: product_brands; Type: TABLE; Schema: inventory; Owner: postgres
--

CREATE TABLE inventory.product_brands (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    name character varying(120) NOT NULL,
    status smallint DEFAULT 1 NOT NULL,
    created_by bigint,
    created_at timestamp with time zone,
    updated_at timestamp with time zone
);


ALTER TABLE inventory.product_brands OWNER TO postgres;

--
-- Name: product_brands_id_seq; Type: SEQUENCE; Schema: inventory; Owner: postgres
--

CREATE SEQUENCE inventory.product_brands_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE inventory.product_brands_id_seq OWNER TO postgres;

--
-- Name: product_brands_id_seq; Type: SEQUENCE OWNED BY; Schema: inventory; Owner: postgres
--

ALTER SEQUENCE inventory.product_brands_id_seq OWNED BY inventory.product_brands.id;


--
-- Name: product_lines; Type: TABLE; Schema: inventory; Owner: postgres
--

CREATE TABLE inventory.product_lines (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    name character varying(120) NOT NULL,
    status smallint DEFAULT 1 NOT NULL,
    created_by bigint,
    created_at timestamp with time zone,
    updated_at timestamp with time zone
);


ALTER TABLE inventory.product_lines OWNER TO postgres;

--
-- Name: product_lines_id_seq; Type: SEQUENCE; Schema: inventory; Owner: postgres
--

CREATE SEQUENCE inventory.product_lines_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE inventory.product_lines_id_seq OWNER TO postgres;

--
-- Name: product_lines_id_seq; Type: SEQUENCE OWNED BY; Schema: inventory; Owner: postgres
--

ALTER SEQUENCE inventory.product_lines_id_seq OWNED BY inventory.product_lines.id;


--
-- Name: product_locations; Type: TABLE; Schema: inventory; Owner: postgres
--

CREATE TABLE inventory.product_locations (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    name character varying(120) NOT NULL,
    status smallint DEFAULT 1 NOT NULL,
    created_by bigint,
    created_at timestamp with time zone,
    updated_at timestamp with time zone
);


ALTER TABLE inventory.product_locations OWNER TO postgres;

--
-- Name: product_locations_id_seq; Type: SEQUENCE; Schema: inventory; Owner: postgres
--

CREATE SEQUENCE inventory.product_locations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE inventory.product_locations_id_seq OWNER TO postgres;

--
-- Name: product_locations_id_seq; Type: SEQUENCE OWNED BY; Schema: inventory; Owner: postgres
--

ALTER SEQUENCE inventory.product_locations_id_seq OWNED BY inventory.product_locations.id;


--
-- Name: product_lots; Type: TABLE; Schema: inventory; Owner: postgres
--

CREATE TABLE inventory.product_lots (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    warehouse_id bigint NOT NULL,
    product_id bigint NOT NULL,
    lot_code character varying(60) NOT NULL,
    manufacture_at date,
    expires_at date,
    received_at timestamp with time zone DEFAULT now() NOT NULL,
    unit_cost numeric(14,4),
    supplier_reference character varying(120),
    status smallint DEFAULT 1 NOT NULL,
    created_by bigint,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE inventory.product_lots OWNER TO postgres;

--
-- Name: product_lots_id_seq; Type: SEQUENCE; Schema: inventory; Owner: postgres
--

CREATE SEQUENCE inventory.product_lots_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE inventory.product_lots_id_seq OWNER TO postgres;

--
-- Name: product_lots_id_seq; Type: SEQUENCE OWNED BY; Schema: inventory; Owner: postgres
--

ALTER SEQUENCE inventory.product_lots_id_seq OWNED BY inventory.product_lots.id;


--
-- Name: product_recipe_items; Type: TABLE; Schema: inventory; Owner: postgres
--

CREATE TABLE inventory.product_recipe_items (
    id bigint NOT NULL,
    recipe_id bigint NOT NULL,
    component_product_id bigint NOT NULL,
    qty_required numeric(14,3) NOT NULL,
    waste_percent numeric(8,4) DEFAULT 0 NOT NULL,
    notes character varying(300),
    CONSTRAINT product_recipe_items_qty_required_check CHECK ((qty_required > (0)::numeric)),
    CONSTRAINT product_recipe_items_waste_percent_check CHECK ((waste_percent >= (0)::numeric))
);


ALTER TABLE inventory.product_recipe_items OWNER TO postgres;

--
-- Name: product_recipe_items_id_seq; Type: SEQUENCE; Schema: inventory; Owner: postgres
--

CREATE SEQUENCE inventory.product_recipe_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE inventory.product_recipe_items_id_seq OWNER TO postgres;

--
-- Name: product_recipe_items_id_seq; Type: SEQUENCE OWNED BY; Schema: inventory; Owner: postgres
--

ALTER SEQUENCE inventory.product_recipe_items_id_seq OWNED BY inventory.product_recipe_items.id;


--
-- Name: product_recipes; Type: TABLE; Schema: inventory; Owner: postgres
--

CREATE TABLE inventory.product_recipes (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    output_product_id bigint NOT NULL,
    code character varying(40) NOT NULL,
    name character varying(150) NOT NULL,
    output_qty numeric(14,3) DEFAULT 1 NOT NULL,
    status smallint DEFAULT 1 NOT NULL,
    created_by bigint,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT product_recipes_output_qty_check CHECK ((output_qty > (0)::numeric))
);


ALTER TABLE inventory.product_recipes OWNER TO postgres;

--
-- Name: product_recipes_id_seq; Type: SEQUENCE; Schema: inventory; Owner: postgres
--

CREATE SEQUENCE inventory.product_recipes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE inventory.product_recipes_id_seq OWNER TO postgres;

--
-- Name: product_recipes_id_seq; Type: SEQUENCE OWNED BY; Schema: inventory; Owner: postgres
--

ALTER SEQUENCE inventory.product_recipes_id_seq OWNED BY inventory.product_recipes.id;


--
-- Name: product_sale_units; Type: TABLE; Schema: inventory; Owner: postgres
--

CREATE TABLE inventory.product_sale_units (
    company_id bigint NOT NULL,
    product_id bigint NOT NULL,
    unit_id bigint NOT NULL,
    is_base boolean DEFAULT false NOT NULL,
    status smallint DEFAULT 1 NOT NULL,
    updated_by bigint,
    updated_at timestamp with time zone
);


ALTER TABLE inventory.product_sale_units OWNER TO postgres;

--
-- Name: product_uom_conversions; Type: TABLE; Schema: inventory; Owner: postgres
--

CREATE TABLE inventory.product_uom_conversions (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    product_id bigint NOT NULL,
    from_unit_id bigint NOT NULL,
    to_unit_id bigint NOT NULL,
    conversion_factor numeric(18,8) NOT NULL,
    status smallint DEFAULT 1 NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT product_uom_conversions_check CHECK ((from_unit_id <> to_unit_id)),
    CONSTRAINT product_uom_conversions_conversion_factor_check CHECK ((conversion_factor > (0)::numeric))
);


ALTER TABLE inventory.product_uom_conversions OWNER TO postgres;

--
-- Name: product_uom_conversions_id_seq; Type: SEQUENCE; Schema: inventory; Owner: postgres
--

CREATE SEQUENCE inventory.product_uom_conversions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE inventory.product_uom_conversions_id_seq OWNER TO postgres;

--
-- Name: product_uom_conversions_id_seq; Type: SEQUENCE OWNED BY; Schema: inventory; Owner: postgres
--

ALTER SEQUENCE inventory.product_uom_conversions_id_seq OWNED BY inventory.product_uom_conversions.id;


--
-- Name: product_warranties; Type: TABLE; Schema: inventory; Owner: postgres
--

CREATE TABLE inventory.product_warranties (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    name character varying(120) NOT NULL,
    status smallint DEFAULT 1 NOT NULL,
    created_by bigint,
    created_at timestamp with time zone,
    updated_at timestamp with time zone
);


ALTER TABLE inventory.product_warranties OWNER TO postgres;

--
-- Name: product_warranties_id_seq; Type: SEQUENCE; Schema: inventory; Owner: postgres
--

CREATE SEQUENCE inventory.product_warranties_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE inventory.product_warranties_id_seq OWNER TO postgres;

--
-- Name: product_warranties_id_seq; Type: SEQUENCE OWNED BY; Schema: inventory; Owner: postgres
--

ALTER SEQUENCE inventory.product_warranties_id_seq OWNED BY inventory.product_warranties.id;


--
-- Name: products; Type: TABLE; Schema: inventory; Owner: postgres
--

CREATE TABLE inventory.products (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    sku character varying(60),
    barcode character varying(80),
    category_id bigint,
    unit_id bigint,
    name character varying(250) NOT NULL,
    description text,
    sale_price numeric(14,2) DEFAULT 0 NOT NULL,
    cost_price numeric(14,2) DEFAULT 0 NOT NULL,
    is_stockable boolean DEFAULT true NOT NULL,
    lot_tracking boolean DEFAULT false NOT NULL,
    has_expiration boolean DEFAULT false NOT NULL,
    multi_uom boolean DEFAULT false NOT NULL,
    status smallint DEFAULT 1 NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    deleted_at timestamp with time zone,
    line_id bigint,
    brand_id bigint,
    location_id bigint,
    warranty_id bigint,
    product_nature character varying(20) DEFAULT 'PRODUCT'::character varying NOT NULL,
    sunat_code character varying(40),
    image_url text,
    seller_commission_percent numeric(8,4) DEFAULT 0 NOT NULL
);


ALTER TABLE inventory.products OWNER TO postgres;

--
-- Name: products_id_seq; Type: SEQUENCE; Schema: inventory; Owner: postgres
--

CREATE SEQUENCE inventory.products_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE inventory.products_id_seq OWNER TO postgres;

--
-- Name: products_id_seq; Type: SEQUENCE OWNED BY; Schema: inventory; Owner: postgres
--

ALTER SEQUENCE inventory.products_id_seq OWNED BY inventory.products.id;


--
-- Name: report_requests; Type: TABLE; Schema: inventory; Owner: postgres
--

CREATE TABLE inventory.report_requests (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    branch_id bigint,
    requested_by bigint NOT NULL,
    report_type character varying(40) NOT NULL,
    filters_json jsonb,
    status character varying(20) DEFAULT 'PENDING'::character varying NOT NULL,
    result_json jsonb,
    error_message text,
    requested_at timestamp with time zone NOT NULL,
    started_at timestamp with time zone,
    finished_at timestamp with time zone,
    created_at timestamp with time zone,
    updated_at timestamp with time zone
);


ALTER TABLE inventory.report_requests OWNER TO postgres;

--
-- Name: report_requests_id_seq; Type: SEQUENCE; Schema: inventory; Owner: postgres
--

CREATE SEQUENCE inventory.report_requests_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE inventory.report_requests_id_seq OWNER TO postgres;

--
-- Name: report_requests_id_seq; Type: SEQUENCE OWNED BY; Schema: inventory; Owner: postgres
--

ALTER SEQUENCE inventory.report_requests_id_seq OWNED BY inventory.report_requests.id;


--
-- Name: stock_daily_snapshot; Type: TABLE; Schema: inventory; Owner: postgres
--

CREATE TABLE inventory.stock_daily_snapshot (
    snapshot_date date NOT NULL,
    company_id bigint NOT NULL,
    branch_id bigint,
    warehouse_id bigint NOT NULL,
    product_id bigint NOT NULL,
    lot_id bigint NOT NULL,
    qty_in numeric(18,8) DEFAULT 0 NOT NULL,
    qty_out numeric(18,8) DEFAULT 0 NOT NULL,
    qty_net numeric(18,8) DEFAULT 0 NOT NULL,
    value_in numeric(18,8) DEFAULT 0 NOT NULL,
    value_out numeric(18,8) DEFAULT 0 NOT NULL,
    value_net numeric(18,8) DEFAULT 0 NOT NULL,
    movement_count integer DEFAULT 0 NOT NULL,
    first_moved_at timestamp with time zone,
    last_moved_at timestamp with time zone,
    updated_at timestamp with time zone
);


ALTER TABLE inventory.stock_daily_snapshot OWNER TO postgres;

--
-- Name: stock_entries; Type: TABLE; Schema: inventory; Owner: postgres
--

CREATE TABLE inventory.stock_entries (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    branch_id bigint,
    warehouse_id bigint NOT NULL,
    entry_type character varying(20) NOT NULL,
    reference_no character varying(60),
    supplier_reference character varying(120),
    issue_at timestamp with time zone NOT NULL,
    status character varying(20) DEFAULT 'APPLIED'::character varying NOT NULL,
    notes character varying(300),
    created_by bigint,
    updated_by bigint,
    created_at timestamp with time zone,
    updated_at timestamp with time zone,
    payment_method_id bigint
);


ALTER TABLE inventory.stock_entries OWNER TO postgres;

--
-- Name: stock_entries_id_seq; Type: SEQUENCE; Schema: inventory; Owner: postgres
--

CREATE SEQUENCE inventory.stock_entries_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE inventory.stock_entries_id_seq OWNER TO postgres;

--
-- Name: stock_entries_id_seq; Type: SEQUENCE OWNED BY; Schema: inventory; Owner: postgres
--

ALTER SEQUENCE inventory.stock_entries_id_seq OWNED BY inventory.stock_entries.id;


--
-- Name: stock_entry_items; Type: TABLE; Schema: inventory; Owner: postgres
--

CREATE TABLE inventory.stock_entry_items (
    id bigint NOT NULL,
    entry_id bigint NOT NULL,
    product_id bigint NOT NULL,
    lot_id bigint,
    qty numeric(18,8) NOT NULL,
    unit_cost numeric(18,8) DEFAULT 0 NOT NULL,
    notes character varying(200),
    created_at timestamp with time zone,
    tax_category_id bigint,
    tax_rate numeric(8,4) DEFAULT 0 NOT NULL
);


ALTER TABLE inventory.stock_entry_items OWNER TO postgres;

--
-- Name: stock_entry_items_id_seq; Type: SEQUENCE; Schema: inventory; Owner: postgres
--

CREATE SEQUENCE inventory.stock_entry_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE inventory.stock_entry_items_id_seq OWNER TO postgres;

--
-- Name: stock_entry_items_id_seq; Type: SEQUENCE OWNED BY; Schema: inventory; Owner: postgres
--

ALTER SEQUENCE inventory.stock_entry_items_id_seq OWNED BY inventory.stock_entry_items.id;


--
-- Name: stock_transformation_lines; Type: TABLE; Schema: inventory; Owner: postgres
--

CREATE TABLE inventory.stock_transformation_lines (
    id bigint NOT NULL,
    transformation_id bigint NOT NULL,
    line_type character varying(20) NOT NULL,
    product_id bigint NOT NULL,
    unit_id bigint,
    lot_id bigint,
    qty numeric(14,3) NOT NULL,
    qty_base numeric(14,3),
    conversion_factor numeric(18,8) DEFAULT 1 NOT NULL,
    unit_cost numeric(14,4),
    notes character varying(300),
    CONSTRAINT stock_transformation_lines_conversion_factor_check CHECK ((conversion_factor > (0)::numeric)),
    CONSTRAINT stock_transformation_lines_line_type_check CHECK (((line_type)::text = ANY (ARRAY[('INPUT'::character varying)::text, ('OUTPUT'::character varying)::text]))),
    CONSTRAINT stock_transformation_lines_qty_check CHECK ((qty > (0)::numeric))
);


ALTER TABLE inventory.stock_transformation_lines OWNER TO postgres;

--
-- Name: stock_transformation_lines_id_seq; Type: SEQUENCE; Schema: inventory; Owner: postgres
--

CREATE SEQUENCE inventory.stock_transformation_lines_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE inventory.stock_transformation_lines_id_seq OWNER TO postgres;

--
-- Name: stock_transformation_lines_id_seq; Type: SEQUENCE OWNED BY; Schema: inventory; Owner: postgres
--

ALTER SEQUENCE inventory.stock_transformation_lines_id_seq OWNED BY inventory.stock_transformation_lines.id;


--
-- Name: stock_transformations; Type: TABLE; Schema: inventory; Owner: postgres
--

CREATE TABLE inventory.stock_transformations (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    branch_id bigint,
    warehouse_id bigint NOT NULL,
    recipe_id bigint,
    transformation_code character varying(40) NOT NULL,
    executed_at timestamp with time zone DEFAULT now() NOT NULL,
    status character varying(20) DEFAULT 'CONFIRMED'::character varying NOT NULL,
    notes character varying(300),
    created_by bigint,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT stock_transformations_status_check CHECK (((status)::text = ANY (ARRAY[('DRAFT'::character varying)::text, ('CONFIRMED'::character varying)::text, ('CANCELED'::character varying)::text])))
);


ALTER TABLE inventory.stock_transformations OWNER TO postgres;

--
-- Name: stock_transformations_id_seq; Type: SEQUENCE; Schema: inventory; Owner: postgres
--

CREATE SEQUENCE inventory.stock_transformations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE inventory.stock_transformations_id_seq OWNER TO postgres;

--
-- Name: stock_transformations_id_seq; Type: SEQUENCE OWNED BY; Schema: inventory; Owner: postgres
--

ALTER SEQUENCE inventory.stock_transformations_id_seq OWNED BY inventory.stock_transformations.id;


--
-- Name: transformation_settings; Type: TABLE; Schema: inventory; Owner: postgres
--

CREATE TABLE inventory.transformation_settings (
    company_id bigint NOT NULL,
    is_enabled boolean DEFAULT false NOT NULL,
    auto_consume_components boolean DEFAULT true NOT NULL,
    allow_negative_components boolean DEFAULT false NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE inventory.transformation_settings OWNER TO postgres;

--
-- Name: warehouses; Type: TABLE; Schema: inventory; Owner: postgres
--

CREATE TABLE inventory.warehouses (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    branch_id bigint,
    code character varying(30) NOT NULL,
    name character varying(120) NOT NULL,
    address character varying(250),
    status smallint DEFAULT 1 NOT NULL
);


ALTER TABLE inventory.warehouses OWNER TO postgres;

--
-- Name: warehouses_id_seq; Type: SEQUENCE; Schema: inventory; Owner: postgres
--

CREATE SEQUENCE inventory.warehouses_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE inventory.warehouses_id_seq OWNER TO postgres;

--
-- Name: warehouses_id_seq; Type: SEQUENCE OWNED BY; Schema: inventory; Owner: postgres
--

ALTER SEQUENCE inventory.warehouses_id_seq OWNED BY inventory.warehouses.id;


--
-- Name: additional_legends; Type: TABLE; Schema: master; Owner: postgres
--

CREATE TABLE master.additional_legends (
    id integer NOT NULL,
    code character varying(10) NOT NULL,
    description character varying(500) NOT NULL,
    status character varying(20) NOT NULL
);


ALTER TABLE master.additional_legends OWNER TO postgres;

--
-- Name: credit_note_reasons; Type: TABLE; Schema: master; Owner: postgres
--

CREATE TABLE master.credit_note_reasons (
    id integer NOT NULL,
    code character varying(4) NOT NULL,
    description character varying(200) NOT NULL,
    is_deleted smallint DEFAULT 0 NOT NULL
);


ALTER TABLE master.credit_note_reasons OWNER TO postgres;

--
-- Name: debit_note_reasons; Type: TABLE; Schema: master; Owner: postgres
--

CREATE TABLE master.debit_note_reasons (
    id integer NOT NULL,
    code character varying(4) NOT NULL,
    description character varying(200) NOT NULL,
    is_deleted smallint DEFAULT 0 NOT NULL
);


ALTER TABLE master.debit_note_reasons OWNER TO postgres;

--
-- Name: detraccion_service_codes; Type: TABLE; Schema: master; Owner: postgres
--

CREATE TABLE master.detraccion_service_codes (
    id integer NOT NULL,
    code character varying(4) NOT NULL,
    name character varying(300) NOT NULL,
    rate_percent numeric(5,2) DEFAULT 10.00 NOT NULL,
    is_active smallint DEFAULT 1 NOT NULL
);


ALTER TABLE master.detraccion_service_codes OWNER TO postgres;

--
-- Name: detraccion_service_codes_id_seq; Type: SEQUENCE; Schema: master; Owner: postgres
--

CREATE SEQUENCE master.detraccion_service_codes_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE master.detraccion_service_codes_id_seq OWNER TO postgres;

--
-- Name: detraccion_service_codes_id_seq; Type: SEQUENCE OWNED BY; Schema: master; Owner: postgres
--

ALTER SEQUENCE master.detraccion_service_codes_id_seq OWNED BY master.detraccion_service_codes.id;


--
-- Name: employee_roles; Type: TABLE; Schema: master; Owner: postgres
--

CREATE TABLE master.employee_roles (
    id integer NOT NULL,
    name character varying(120) NOT NULL,
    is_lawyer smallint DEFAULT 0 NOT NULL,
    status smallint DEFAULT 2 NOT NULL
);


ALTER TABLE master.employee_roles OWNER TO postgres;

--
-- Name: geo_ubigeo; Type: TABLE; Schema: master; Owner: postgres
--

CREATE TABLE master.geo_ubigeo (
    id integer NOT NULL,
    code character(6) NOT NULL,
    full_name character varying(255) NOT NULL,
    population_text character varying(20),
    surface_text character varying(20),
    latitude numeric(10,6),
    longitude numeric(10,6),
    status smallint DEFAULT 2 NOT NULL
);


ALTER TABLE master.geo_ubigeo OWNER TO postgres;

--
-- Name: item_types; Type: TABLE; Schema: master; Owner: postgres
--

CREATE TABLE master.item_types (
    id integer NOT NULL,
    name character varying(50) NOT NULL,
    unit_code character varying(10) NOT NULL
);


ALTER TABLE master.item_types OWNER TO postgres;

--
-- Name: payment_types; Type: TABLE; Schema: master; Owner: postgres
--

CREATE TABLE master.payment_types (
    id integer NOT NULL,
    name character varying(100) NOT NULL,
    comment character varying(300),
    is_active smallint DEFAULT 0 NOT NULL,
    status smallint DEFAULT 2 NOT NULL
);


ALTER TABLE master.payment_types OWNER TO postgres;

--
-- Name: shipment_transfer_reasons; Type: TABLE; Schema: master; Owner: postgres
--

CREATE TABLE master.shipment_transfer_reasons (
    id integer NOT NULL,
    name character varying(220) NOT NULL,
    code character varying(4) NOT NULL,
    status smallint DEFAULT 2 NOT NULL
);


ALTER TABLE master.shipment_transfer_reasons OWNER TO postgres;

--
-- Name: shipment_transport_modes; Type: TABLE; Schema: master; Owner: postgres
--

CREATE TABLE master.shipment_transport_modes (
    id integer NOT NULL,
    name character varying(150) NOT NULL,
    code character varying(4) NOT NULL,
    status smallint DEFAULT 2 NOT NULL
);


ALTER TABLE master.shipment_transport_modes OWNER TO postgres;

--
-- Name: sunat_uom; Type: TABLE; Schema: master; Owner: postgres
--

CREATE TABLE master.sunat_uom (
    id integer NOT NULL,
    code character varying(10) NOT NULL,
    name character varying(120),
    is_active smallint DEFAULT 1 NOT NULL
);


ALTER TABLE master.sunat_uom OWNER TO postgres;

--
-- Name: tax_codes; Type: TABLE; Schema: master; Owner: postgres
--

CREATE TABLE master.tax_codes (
    code character varying(10) NOT NULL,
    description character varying(500),
    international_code character varying(10),
    short_name character varying(100)
);


ALTER TABLE master.tax_codes OWNER TO postgres;

--
-- Name: vat_categories; Type: TABLE; Schema: master; Owner: postgres
--

CREATE TABLE master.vat_categories (
    id integer NOT NULL,
    code character varying(4) NOT NULL,
    name character varying(200) NOT NULL,
    is_deleted smallint DEFAULT 0 NOT NULL,
    tax_code character varying(10)
);


ALTER TABLE master.vat_categories OWNER TO postgres;

--
-- Name: failed_jobs; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.failed_jobs (
    id bigint NOT NULL,
    connection text NOT NULL,
    queue text NOT NULL,
    payload text NOT NULL,
    exception text NOT NULL,
    failed_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE public.failed_jobs OWNER TO postgres;

--
-- Name: failed_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.failed_jobs_id_seq OWNER TO postgres;

--
-- Name: failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.failed_jobs_id_seq OWNED BY public.failed_jobs.id;


--
-- Name: migrations; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


ALTER TABLE public.migrations OWNER TO postgres;

--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.migrations_id_seq OWNER TO postgres;

--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;


--
-- Name: password_resets; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.password_resets (
    email character varying(255) NOT NULL,
    token character varying(255) NOT NULL,
    created_at timestamp(0) without time zone
);


ALTER TABLE public.password_resets OWNER TO postgres;

--
-- Name: users; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.users (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    email character varying(255) NOT NULL,
    email_verified_at timestamp(0) without time zone,
    password character varying(255) NOT NULL,
    remember_token character varying(100),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.users OWNER TO postgres;

--
-- Name: users_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.users_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.users_id_seq OWNER TO postgres;

--
-- Name: users_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.users_id_seq OWNED BY public.users.id;


--
-- Name: cash_movements; Type: TABLE; Schema: sales; Owner: postgres
--

CREATE TABLE sales.cash_movements (
    id bigint NOT NULL,
    cash_session_id bigint NOT NULL,
    movement_type character varying(20) NOT NULL,
    payment_method_id bigint,
    amount numeric(14,2) NOT NULL,
    ref_type character varying(30),
    ref_id bigint,
    notes character varying(300),
    created_by bigint,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    company_id bigint,
    branch_id bigint,
    cash_register_id bigint,
    description text,
    user_id bigint,
    movement_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT cash_movements_amount_check CHECK ((amount > (0)::numeric)),
    CONSTRAINT cash_movements_movement_type_check CHECK (((movement_type)::text = ANY (ARRAY[('INCOME'::character varying)::text, ('EXPENSE'::character varying)::text, ('ADJUSTMENT'::character varying)::text])))
);


ALTER TABLE sales.cash_movements OWNER TO postgres;

--
-- Name: TABLE cash_movements; Type: COMMENT; Schema: sales; Owner: postgres
--

COMMENT ON TABLE sales.cash_movements IS 'Ingresos y egresos de caja (manuales y automaticos)';


--
-- Name: cash_movements_id_seq; Type: SEQUENCE; Schema: sales; Owner: postgres
--

CREATE SEQUENCE sales.cash_movements_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE sales.cash_movements_id_seq OWNER TO postgres;

--
-- Name: cash_movements_id_seq; Type: SEQUENCE OWNED BY; Schema: sales; Owner: postgres
--

ALTER SEQUENCE sales.cash_movements_id_seq OWNED BY sales.cash_movements.id;


--
-- Name: cash_registers; Type: TABLE; Schema: sales; Owner: postgres
--

CREATE TABLE sales.cash_registers (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    branch_id bigint,
    code character varying(30) NOT NULL,
    name character varying(120) NOT NULL,
    status smallint DEFAULT 1 NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE sales.cash_registers OWNER TO postgres;

--
-- Name: cash_registers_id_seq; Type: SEQUENCE; Schema: sales; Owner: postgres
--

CREATE SEQUENCE sales.cash_registers_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE sales.cash_registers_id_seq OWNER TO postgres;

--
-- Name: cash_registers_id_seq; Type: SEQUENCE OWNED BY; Schema: sales; Owner: postgres
--

ALTER SEQUENCE sales.cash_registers_id_seq OWNED BY sales.cash_registers.id;


--
-- Name: cash_sessions; Type: TABLE; Schema: sales; Owner: postgres
--

CREATE TABLE sales.cash_sessions (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    branch_id bigint,
    cash_register_id bigint NOT NULL,
    opened_by bigint NOT NULL,
    closed_by bigint,
    opened_at timestamp with time zone DEFAULT now() NOT NULL,
    closed_at timestamp with time zone,
    opening_balance numeric(14,2) DEFAULT 0 NOT NULL,
    closing_balance numeric(14,2),
    expected_balance numeric(14,2),
    difference_amount numeric(14,2),
    status character varying(20) DEFAULT 'OPEN'::character varying NOT NULL,
    notes character varying(300),
    user_id bigint,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT cash_sessions_status_check CHECK (((status)::text = ANY (ARRAY[('OPEN'::character varying)::text, ('CLOSED'::character varying)::text, ('CANCELED'::character varying)::text])))
);


ALTER TABLE sales.cash_sessions OWNER TO postgres;

--
-- Name: TABLE cash_sessions; Type: COMMENT; Schema: sales; Owner: postgres
--

COMMENT ON TABLE sales.cash_sessions IS 'Apertura y cierre de caja por caja registradora';


--
-- Name: COLUMN cash_sessions.closing_balance; Type: COMMENT; Schema: sales; Owner: postgres
--

COMMENT ON COLUMN sales.cash_sessions.closing_balance IS 'Saldo fisico contado al cierre';


--
-- Name: COLUMN cash_sessions.expected_balance; Type: COMMENT; Schema: sales; Owner: postgres
--

COMMENT ON COLUMN sales.cash_sessions.expected_balance IS 'Saldo calculado: saldo_apertura + ingresos - egresos';


--
-- Name: cash_sessions_id_seq; Type: SEQUENCE; Schema: sales; Owner: postgres
--

CREATE SEQUENCE sales.cash_sessions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE sales.cash_sessions_id_seq OWNER TO postgres;

--
-- Name: cash_sessions_id_seq; Type: SEQUENCE OWNED BY; Schema: sales; Owner: postgres
--

ALTER SEQUENCE sales.cash_sessions_id_seq OWNED BY sales.cash_sessions.id;


--
-- Name: commercial_document_item_lots; Type: TABLE; Schema: sales; Owner: postgres
--

CREATE TABLE sales.commercial_document_item_lots (
    id bigint NOT NULL,
    document_item_id bigint NOT NULL,
    lot_id bigint NOT NULL,
    qty numeric(14,3) NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT commercial_document_item_lots_qty_check CHECK ((qty > (0)::numeric))
);


ALTER TABLE sales.commercial_document_item_lots OWNER TO postgres;

--
-- Name: commercial_document_item_lots_id_seq; Type: SEQUENCE; Schema: sales; Owner: postgres
--

CREATE SEQUENCE sales.commercial_document_item_lots_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE sales.commercial_document_item_lots_id_seq OWNER TO postgres;

--
-- Name: commercial_document_item_lots_id_seq; Type: SEQUENCE OWNED BY; Schema: sales; Owner: postgres
--

ALTER SEQUENCE sales.commercial_document_item_lots_id_seq OWNED BY sales.commercial_document_item_lots.id;


--
-- Name: commercial_document_items; Type: TABLE; Schema: sales; Owner: postgres
--

CREATE TABLE sales.commercial_document_items (
    id bigint NOT NULL,
    document_id bigint NOT NULL,
    line_no integer NOT NULL,
    product_id bigint,
    unit_id bigint,
    price_tier_id bigint,
    tax_category_id integer,
    description character varying(500) NOT NULL,
    qty numeric(14,3) NOT NULL,
    qty_base numeric(14,3),
    conversion_factor numeric(18,8) DEFAULT 1 NOT NULL,
    base_unit_price numeric(14,4),
    unit_price numeric(14,4) NOT NULL,
    unit_cost numeric(14,4) DEFAULT 0 NOT NULL,
    wholesale_discount_percent numeric(8,4) DEFAULT 0 NOT NULL,
    price_source character varying(20) DEFAULT 'MANUAL'::character varying NOT NULL,
    discount_total numeric(14,2) DEFAULT 0 NOT NULL,
    tax_total numeric(14,2) DEFAULT 0 NOT NULL,
    subtotal numeric(14,2) DEFAULT 0 NOT NULL,
    total numeric(14,2) DEFAULT 0 NOT NULL,
    metadata jsonb,
    CONSTRAINT commercial_document_items_conversion_factor_check CHECK ((conversion_factor > (0)::numeric)),
    CONSTRAINT commercial_document_items_price_source_check CHECK (((price_source)::text = ANY (ARRAY[('MANUAL'::character varying)::text, ('TIER'::character varying)::text, ('PROFILE'::character varying)::text]))),
    CONSTRAINT commercial_document_items_qty_check CHECK ((qty > (0)::numeric)),
    CONSTRAINT commercial_document_items_wholesale_discount_percent_check CHECK ((wholesale_discount_percent >= (0)::numeric))
);


ALTER TABLE sales.commercial_document_items OWNER TO postgres;

--
-- Name: commercial_document_items_id_seq; Type: SEQUENCE; Schema: sales; Owner: postgres
--

CREATE SEQUENCE sales.commercial_document_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE sales.commercial_document_items_id_seq OWNER TO postgres;

--
-- Name: commercial_document_items_id_seq; Type: SEQUENCE OWNED BY; Schema: sales; Owner: postgres
--

ALTER SEQUENCE sales.commercial_document_items_id_seq OWNED BY sales.commercial_document_items.id;


--
-- Name: commercial_document_payments; Type: TABLE; Schema: sales; Owner: postgres
--

CREATE TABLE sales.commercial_document_payments (
    id bigint NOT NULL,
    document_id bigint NOT NULL,
    payment_method_id bigint NOT NULL,
    amount numeric(14,2) NOT NULL,
    due_at timestamp with time zone,
    paid_at timestamp with time zone,
    status character varying(20) DEFAULT 'PENDING'::character varying NOT NULL,
    notes character varying(300),
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT commercial_document_payments_amount_check CHECK ((amount > (0)::numeric)),
    CONSTRAINT commercial_document_payments_status_check CHECK (((status)::text = ANY (ARRAY[('PENDING'::character varying)::text, ('PAID'::character varying)::text, ('CANCELED'::character varying)::text])))
);


ALTER TABLE sales.commercial_document_payments OWNER TO postgres;

--
-- Name: commercial_document_payments_id_seq; Type: SEQUENCE; Schema: sales; Owner: postgres
--

CREATE SEQUENCE sales.commercial_document_payments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE sales.commercial_document_payments_id_seq OWNER TO postgres;

--
-- Name: commercial_document_payments_id_seq; Type: SEQUENCE OWNED BY; Schema: sales; Owner: postgres
--

ALTER SEQUENCE sales.commercial_document_payments_id_seq OWNED BY sales.commercial_document_payments.id;


--
-- Name: commercial_documents; Type: TABLE; Schema: sales; Owner: postgres
--

CREATE TABLE sales.commercial_documents (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    branch_id bigint,
    warehouse_id bigint,
    document_kind character varying(30) NOT NULL,
    series character varying(10) NOT NULL,
    number bigint NOT NULL,
    issue_at timestamp with time zone NOT NULL,
    due_at timestamp with time zone,
    customer_id bigint NOT NULL,
    currency_id bigint NOT NULL,
    payment_method_id bigint,
    exchange_rate numeric(10,4),
    source_document_id bigint,
    reference_document_id bigint,
    reference_reason_code character varying(10),
    tax_affectation_code character varying(10),
    seller_user_id bigint,
    subtotal numeric(14,2) DEFAULT 0 NOT NULL,
    tax_total numeric(14,2) DEFAULT 0 NOT NULL,
    total numeric(14,2) DEFAULT 0 NOT NULL,
    paid_total numeric(14,2) DEFAULT 0 NOT NULL,
    balance_due numeric(14,2) DEFAULT 0 NOT NULL,
    discount_total numeric(14,2) DEFAULT 0 NOT NULL,
    status character varying(30) DEFAULT 'DRAFT'::character varying NOT NULL,
    external_status character varying(30),
    notes text,
    metadata jsonb,
    created_by bigint,
    updated_by bigint,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    deleted_at timestamp with time zone,
    CONSTRAINT commercial_documents_document_kind_check CHECK (((document_kind)::text = ANY (ARRAY[('QUOTATION'::character varying)::text, ('SALES_ORDER'::character varying)::text, ('INVOICE'::character varying)::text, ('RECEIPT'::character varying)::text, ('CREDIT_NOTE'::character varying)::text, ('DEBIT_NOTE'::character varying)::text]))),
    CONSTRAINT commercial_documents_status_check CHECK (((status)::text = ANY (ARRAY[('DRAFT'::character varying)::text, ('APPROVED'::character varying)::text, ('ISSUED'::character varying)::text, ('VOID'::character varying)::text, ('CANCELED'::character varying)::text])))
);


ALTER TABLE sales.commercial_documents OWNER TO postgres;

--
-- Name: commercial_documents_id_seq; Type: SEQUENCE; Schema: sales; Owner: postgres
--

CREATE SEQUENCE sales.commercial_documents_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE sales.commercial_documents_id_seq OWNER TO postgres;

--
-- Name: commercial_documents_id_seq; Type: SEQUENCE OWNED BY; Schema: sales; Owner: postgres
--

ALTER SEQUENCE sales.commercial_documents_id_seq OWNED BY sales.commercial_documents.id;


--
-- Name: customer_price_profiles; Type: TABLE; Schema: sales; Owner: postgres
--

CREATE TABLE sales.customer_price_profiles (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    customer_id bigint NOT NULL,
    default_tier_id bigint,
    discount_percent numeric(8,4) DEFAULT 0 NOT NULL,
    status smallint DEFAULT 1 NOT NULL,
    CONSTRAINT customer_price_profiles_discount_percent_check CHECK ((discount_percent >= (0)::numeric))
);


ALTER TABLE sales.customer_price_profiles OWNER TO postgres;

--
-- Name: customer_price_profiles_id_seq; Type: SEQUENCE; Schema: sales; Owner: postgres
--

CREATE SEQUENCE sales.customer_price_profiles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE sales.customer_price_profiles_id_seq OWNER TO postgres;

--
-- Name: customer_price_profiles_id_seq; Type: SEQUENCE OWNED BY; Schema: sales; Owner: postgres
--

ALTER SEQUENCE sales.customer_price_profiles_id_seq OWNED BY sales.customer_price_profiles.id;


--
-- Name: customer_types; Type: TABLE; Schema: sales; Owner: postgres
--

CREATE TABLE sales.customer_types (
    id bigint NOT NULL,
    name character varying(120) NOT NULL,
    sunat_code integer NOT NULL,
    sunat_abbr character varying(120),
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE sales.customer_types OWNER TO postgres;

--
-- Name: customer_types_id_seq; Type: SEQUENCE; Schema: sales; Owner: postgres
--

CREATE SEQUENCE sales.customer_types_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE sales.customer_types_id_seq OWNER TO postgres;

--
-- Name: customer_types_id_seq; Type: SEQUENCE OWNED BY; Schema: sales; Owner: postgres
--

ALTER SEQUENCE sales.customer_types_id_seq OWNED BY sales.customer_types.id;


--
-- Name: customers; Type: TABLE; Schema: sales; Owner: postgres
--

CREATE TABLE sales.customers (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    doc_type character varying(20),
    doc_number character varying(30),
    legal_name character varying(220),
    trade_name character varying(220),
    first_name character varying(120),
    last_name character varying(120),
    email character varying(150),
    phone character varying(70),
    address character varying(250),
    plate character varying(30),
    status smallint DEFAULT 1 NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    deleted_at timestamp with time zone,
    customer_type_id bigint
);


ALTER TABLE sales.customers OWNER TO postgres;

--
-- Name: customers_id_seq; Type: SEQUENCE; Schema: sales; Owner: postgres
--

CREATE SEQUENCE sales.customers_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE sales.customers_id_seq OWNER TO postgres;

--
-- Name: customers_id_seq; Type: SEQUENCE OWNED BY; Schema: sales; Owner: postgres
--

ALTER SEQUENCE sales.customers_id_seq OWNED BY sales.customers.id;


--
-- Name: document_sequences; Type: TABLE; Schema: sales; Owner: postgres
--

CREATE TABLE sales.document_sequences (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    branch_id bigint,
    warehouse_id bigint,
    document_kind character varying(30) NOT NULL,
    series character varying(10) NOT NULL,
    current_number bigint DEFAULT 0 NOT NULL,
    status smallint DEFAULT 1 NOT NULL,
    CONSTRAINT document_sequences_document_kind_check CHECK (((document_kind)::text = ANY (ARRAY[('QUOTATION'::character varying)::text, ('SALES_ORDER'::character varying)::text, ('INVOICE'::character varying)::text, ('RECEIPT'::character varying)::text, ('CREDIT_NOTE'::character varying)::text, ('DEBIT_NOTE'::character varying)::text])))
);


ALTER TABLE sales.document_sequences OWNER TO postgres;

--
-- Name: document_sequences_id_seq; Type: SEQUENCE; Schema: sales; Owner: postgres
--

CREATE SEQUENCE sales.document_sequences_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE sales.document_sequences_id_seq OWNER TO postgres;

--
-- Name: document_sequences_id_seq; Type: SEQUENCE OWNED BY; Schema: sales; Owner: postgres
--

ALTER SEQUENCE sales.document_sequences_id_seq OWNED BY sales.document_sequences.id;


--
-- Name: order_sequences; Type: TABLE; Schema: sales; Owner: postgres
--

CREATE TABLE sales.order_sequences (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    branch_id bigint,
    doc_type character varying(20) DEFAULT 'NP'::character varying NOT NULL,
    series character varying(10) DEFAULT 'NP01'::character varying NOT NULL,
    current_number bigint DEFAULT 0 NOT NULL
);


ALTER TABLE sales.order_sequences OWNER TO postgres;

--
-- Name: order_sequences_id_seq; Type: SEQUENCE; Schema: sales; Owner: postgres
--

CREATE SEQUENCE sales.order_sequences_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE sales.order_sequences_id_seq OWNER TO postgres;

--
-- Name: order_sequences_id_seq; Type: SEQUENCE OWNED BY; Schema: sales; Owner: postgres
--

ALTER SEQUENCE sales.order_sequences_id_seq OWNED BY sales.order_sequences.id;


--
-- Name: price_tiers; Type: TABLE; Schema: sales; Owner: postgres
--

CREATE TABLE sales.price_tiers (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    code character varying(30) NOT NULL,
    name character varying(120) NOT NULL,
    min_qty numeric(14,3) DEFAULT 1 NOT NULL,
    max_qty numeric(14,3),
    priority integer DEFAULT 1 NOT NULL,
    status smallint DEFAULT 1 NOT NULL,
    CONSTRAINT price_tiers_min_qty_check CHECK ((min_qty > (0)::numeric))
);


ALTER TABLE sales.price_tiers OWNER TO postgres;

--
-- Name: price_tiers_id_seq; Type: SEQUENCE; Schema: sales; Owner: postgres
--

CREATE SEQUENCE sales.price_tiers_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE sales.price_tiers_id_seq OWNER TO postgres;

--
-- Name: price_tiers_id_seq; Type: SEQUENCE OWNED BY; Schema: sales; Owner: postgres
--

ALTER SEQUENCE sales.price_tiers_id_seq OWNED BY sales.price_tiers.id;


--
-- Name: product_price_tier_values; Type: TABLE; Schema: sales; Owner: postgres
--

CREATE TABLE sales.product_price_tier_values (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    product_id bigint NOT NULL,
    price_tier_id bigint NOT NULL,
    unit_id bigint,
    unit_price numeric(18,6) NOT NULL,
    status smallint DEFAULT 1 NOT NULL,
    updated_by bigint,
    updated_at timestamp with time zone
);


ALTER TABLE sales.product_price_tier_values OWNER TO postgres;

--
-- Name: product_price_tier_values_id_seq; Type: SEQUENCE; Schema: sales; Owner: postgres
--

CREATE SEQUENCE sales.product_price_tier_values_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE sales.product_price_tier_values_id_seq OWNER TO postgres;

--
-- Name: product_price_tier_values_id_seq; Type: SEQUENCE OWNED BY; Schema: sales; Owner: postgres
--

ALTER SEQUENCE sales.product_price_tier_values_id_seq OWNED BY sales.product_price_tier_values.id;


--
-- Name: product_tier_prices; Type: TABLE; Schema: sales; Owner: postgres
--

CREATE TABLE sales.product_tier_prices (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    product_id bigint NOT NULL,
    tier_id bigint NOT NULL,
    currency_id bigint NOT NULL,
    unit_price numeric(14,4) NOT NULL,
    valid_from timestamp with time zone,
    valid_to timestamp with time zone,
    status smallint DEFAULT 1 NOT NULL,
    CONSTRAINT product_tier_prices_unit_price_check CHECK ((unit_price >= (0)::numeric))
);


ALTER TABLE sales.product_tier_prices OWNER TO postgres;

--
-- Name: product_tier_prices_id_seq; Type: SEQUENCE; Schema: sales; Owner: postgres
--

CREATE SEQUENCE sales.product_tier_prices_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE sales.product_tier_prices_id_seq OWNER TO postgres;

--
-- Name: product_tier_prices_id_seq; Type: SEQUENCE OWNED BY; Schema: sales; Owner: postgres
--

ALTER SEQUENCE sales.product_tier_prices_id_seq OWNED BY sales.product_tier_prices.id;


--
-- Name: sales_order_item_lots; Type: TABLE; Schema: sales; Owner: postgres
--

CREATE TABLE sales.sales_order_item_lots (
    id bigint NOT NULL,
    sales_order_item_id bigint NOT NULL,
    lot_id bigint NOT NULL,
    qty numeric(14,3) NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT sales_order_item_lots_qty_check CHECK ((qty > (0)::numeric))
);


ALTER TABLE sales.sales_order_item_lots OWNER TO postgres;

--
-- Name: sales_order_item_lots_id_seq; Type: SEQUENCE; Schema: sales; Owner: postgres
--

CREATE SEQUENCE sales.sales_order_item_lots_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE sales.sales_order_item_lots_id_seq OWNER TO postgres;

--
-- Name: sales_order_item_lots_id_seq; Type: SEQUENCE OWNED BY; Schema: sales; Owner: postgres
--

ALTER SEQUENCE sales.sales_order_item_lots_id_seq OWNED BY sales.sales_order_item_lots.id;


--
-- Name: sales_order_items; Type: TABLE; Schema: sales; Owner: postgres
--

CREATE TABLE sales.sales_order_items (
    id bigint NOT NULL,
    order_id bigint NOT NULL,
    line_no integer NOT NULL,
    product_id bigint,
    unit_id bigint,
    description character varying(500) NOT NULL,
    qty numeric(14,3) NOT NULL,
    unit_price numeric(14,4) NOT NULL,
    unit_cost numeric(14,4) DEFAULT 0 NOT NULL,
    discount_total numeric(14,2) DEFAULT 0 NOT NULL,
    tax_total numeric(14,2) DEFAULT 0 NOT NULL,
    subtotal numeric(14,2) DEFAULT 0 NOT NULL,
    total numeric(14,2) DEFAULT 0 NOT NULL
);


ALTER TABLE sales.sales_order_items OWNER TO postgres;

--
-- Name: sales_order_items_id_seq; Type: SEQUENCE; Schema: sales; Owner: postgres
--

CREATE SEQUENCE sales.sales_order_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE sales.sales_order_items_id_seq OWNER TO postgres;

--
-- Name: sales_order_items_id_seq; Type: SEQUENCE OWNED BY; Schema: sales; Owner: postgres
--

ALTER SEQUENCE sales.sales_order_items_id_seq OWNED BY sales.sales_order_items.id;


--
-- Name: sales_order_payments; Type: TABLE; Schema: sales; Owner: postgres
--

CREATE TABLE sales.sales_order_payments (
    id bigint NOT NULL,
    order_id bigint NOT NULL,
    payment_method_id bigint NOT NULL,
    amount numeric(14,2) NOT NULL,
    due_at timestamp with time zone,
    notes character varying(300),
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE sales.sales_order_payments OWNER TO postgres;

--
-- Name: sales_order_payments_id_seq; Type: SEQUENCE; Schema: sales; Owner: postgres
--

CREATE SEQUENCE sales.sales_order_payments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE sales.sales_order_payments_id_seq OWNER TO postgres;

--
-- Name: sales_order_payments_id_seq; Type: SEQUENCE OWNED BY; Schema: sales; Owner: postgres
--

ALTER SEQUENCE sales.sales_order_payments_id_seq OWNED BY sales.sales_order_payments.id;


--
-- Name: sales_orders; Type: TABLE; Schema: sales; Owner: postgres
--

CREATE TABLE sales.sales_orders (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    branch_id bigint,
    warehouse_id bigint,
    customer_id bigint NOT NULL,
    currency_id bigint NOT NULL,
    payment_method_id bigint,
    seller_user_id bigint,
    sequence_series character varying(10) DEFAULT 'NP01'::character varying NOT NULL,
    sequence_number bigint NOT NULL,
    issue_at timestamp with time zone NOT NULL,
    exchange_rate numeric(10,4),
    subtotal numeric(14,2) DEFAULT 0 NOT NULL,
    tax_total numeric(14,2) DEFAULT 0 NOT NULL,
    total numeric(14,2) DEFAULT 0 NOT NULL,
    change_amount numeric(14,2) DEFAULT 0 NOT NULL,
    notes text,
    status character varying(20) DEFAULT 'ACTIVE'::character varying NOT NULL,
    discount_stock boolean DEFAULT true NOT NULL,
    show_image boolean DEFAULT true NOT NULL,
    created_by bigint,
    updated_by bigint,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    deleted_at timestamp with time zone
);


ALTER TABLE sales.sales_orders OWNER TO postgres;

--
-- Name: sales_orders_id_seq; Type: SEQUENCE; Schema: sales; Owner: postgres
--

CREATE SEQUENCE sales.sales_orders_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE sales.sales_orders_id_seq OWNER TO postgres;

--
-- Name: sales_orders_id_seq; Type: SEQUENCE OWNED BY; Schema: sales; Owner: postgres
--

ALTER SEQUENCE sales.sales_orders_id_seq OWNED BY sales.sales_orders.id;


--
-- Name: series_numbers; Type: TABLE; Schema: sales; Owner: postgres
--

CREATE TABLE sales.series_numbers (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    branch_id bigint,
    warehouse_id bigint,
    document_kind character varying(30) NOT NULL,
    series character varying(10) NOT NULL,
    current_number bigint DEFAULT 0 NOT NULL,
    number_padding smallint DEFAULT 8 NOT NULL,
    reset_policy character varying(20) DEFAULT 'NONE'::character varying NOT NULL,
    is_enabled boolean DEFAULT true NOT NULL,
    valid_from date,
    valid_to date,
    updated_by bigint,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT series_numbers_document_kind_check CHECK (((document_kind)::text = ANY (ARRAY[('QUOTATION'::character varying)::text, ('SALES_ORDER'::character varying)::text, ('INVOICE'::character varying)::text, ('RECEIPT'::character varying)::text, ('CREDIT_NOTE'::character varying)::text, ('DEBIT_NOTE'::character varying)::text]))),
    CONSTRAINT series_numbers_number_padding_check CHECK (((number_padding >= 4) AND (number_padding <= 12))),
    CONSTRAINT series_numbers_reset_policy_check CHECK (((reset_policy)::text = ANY (ARRAY[('NONE'::character varying)::text, ('YEARLY'::character varying)::text, ('MONTHLY'::character varying)::text])))
);


ALTER TABLE sales.series_numbers OWNER TO postgres;

--
-- Name: series_numbers_id_seq; Type: SEQUENCE; Schema: sales; Owner: postgres
--

CREATE SEQUENCE sales.series_numbers_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE sales.series_numbers_id_seq OWNER TO postgres;

--
-- Name: series_numbers_id_seq; Type: SEQUENCE OWNED BY; Schema: sales; Owner: postgres
--

ALTER SEQUENCE sales.series_numbers_id_seq OWNED BY sales.series_numbers.id;


--
-- Name: wholesale_settings; Type: TABLE; Schema: sales; Owner: postgres
--

CREATE TABLE sales.wholesale_settings (
    company_id bigint NOT NULL,
    is_enabled boolean DEFAULT false NOT NULL,
    pricing_mode character varying(20) DEFAULT 'PRICE_TIER'::character varying NOT NULL,
    allow_customer_override boolean DEFAULT true NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT wholesale_settings_pricing_mode_check CHECK (((pricing_mode)::text = ANY (ARRAY[('PRICE_TIER'::character varying)::text, ('PRICE_LIST'::character varying)::text])))
);


ALTER TABLE sales.wholesale_settings OWNER TO postgres;

--
-- Name: modules id; Type: DEFAULT; Schema: appcfg; Owner: postgres
--

ALTER TABLE ONLY appcfg.modules ALTER COLUMN id SET DEFAULT nextval('appcfg.modules_id_seq'::regclass);


--
-- Name: saved_filters id; Type: DEFAULT; Schema: appcfg; Owner: postgres
--

ALTER TABLE ONLY appcfg.saved_filters ALTER COLUMN id SET DEFAULT nextval('appcfg.saved_filters_id_seq'::regclass);


--
-- Name: ui_entities id; Type: DEFAULT; Schema: appcfg; Owner: postgres
--

ALTER TABLE ONLY appcfg.ui_entities ALTER COLUMN id SET DEFAULT nextval('appcfg.ui_entities_id_seq'::regclass);


--
-- Name: ui_fields id; Type: DEFAULT; Schema: appcfg; Owner: postgres
--

ALTER TABLE ONLY appcfg.ui_fields ALTER COLUMN id SET DEFAULT nextval('appcfg.ui_fields_id_seq'::regclass);


--
-- Name: permissions id; Type: DEFAULT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.permissions ALTER COLUMN id SET DEFAULT nextval('auth.permissions_id_seq'::regclass);


--
-- Name: refresh_tokens id; Type: DEFAULT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.refresh_tokens ALTER COLUMN id SET DEFAULT nextval('auth.refresh_tokens_id_seq'::regclass);


--
-- Name: roles id; Type: DEFAULT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.roles ALTER COLUMN id SET DEFAULT nextval('auth.roles_id_seq'::regclass);


--
-- Name: users id; Type: DEFAULT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.users ALTER COLUMN id SET DEFAULT nextval('auth.users_id_seq'::regclass);


--
-- Name: documents id; Type: DEFAULT; Schema: billing; Owner: postgres
--

ALTER TABLE ONLY billing.documents ALTER COLUMN id SET DEFAULT nextval('billing.documents_id_seq'::regclass);


--
-- Name: branches id; Type: DEFAULT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.branches ALTER COLUMN id SET DEFAULT nextval('core.branches_id_seq'::regclass);


--
-- Name: companies id; Type: DEFAULT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.companies ALTER COLUMN id SET DEFAULT nextval('core.companies_id_seq'::regclass);


--
-- Name: company_igv_rates id; Type: DEFAULT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.company_igv_rates ALTER COLUMN id SET DEFAULT nextval('core.company_igv_rates_id_seq'::regclass);


--
-- Name: currencies id; Type: DEFAULT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.currencies ALTER COLUMN id SET DEFAULT nextval('core.currencies_id_seq'::regclass);


--
-- Name: payment_methods id; Type: DEFAULT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.payment_methods ALTER COLUMN id SET DEFAULT nextval('core.payment_methods_id_seq'::regclass);


--
-- Name: units id; Type: DEFAULT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.units ALTER COLUMN id SET DEFAULT nextval('core.units_id_seq'::regclass);


--
-- Name: categories id; Type: DEFAULT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.categories ALTER COLUMN id SET DEFAULT nextval('inventory.categories_id_seq'::regclass);


--
-- Name: inventory_ledger id; Type: DEFAULT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.inventory_ledger ALTER COLUMN id SET DEFAULT nextval('inventory.inventory_ledger_id_seq'::regclass);


--
-- Name: outbox_events id; Type: DEFAULT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.outbox_events ALTER COLUMN id SET DEFAULT nextval('inventory.outbox_events_id_seq'::regclass);


--
-- Name: product_brands id; Type: DEFAULT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.product_brands ALTER COLUMN id SET DEFAULT nextval('inventory.product_brands_id_seq'::regclass);


--
-- Name: product_lines id; Type: DEFAULT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.product_lines ALTER COLUMN id SET DEFAULT nextval('inventory.product_lines_id_seq'::regclass);


--
-- Name: product_locations id; Type: DEFAULT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.product_locations ALTER COLUMN id SET DEFAULT nextval('inventory.product_locations_id_seq'::regclass);


--
-- Name: product_lots id; Type: DEFAULT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.product_lots ALTER COLUMN id SET DEFAULT nextval('inventory.product_lots_id_seq'::regclass);


--
-- Name: product_recipe_items id; Type: DEFAULT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.product_recipe_items ALTER COLUMN id SET DEFAULT nextval('inventory.product_recipe_items_id_seq'::regclass);


--
-- Name: product_recipes id; Type: DEFAULT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.product_recipes ALTER COLUMN id SET DEFAULT nextval('inventory.product_recipes_id_seq'::regclass);


--
-- Name: product_uom_conversions id; Type: DEFAULT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.product_uom_conversions ALTER COLUMN id SET DEFAULT nextval('inventory.product_uom_conversions_id_seq'::regclass);


--
-- Name: product_warranties id; Type: DEFAULT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.product_warranties ALTER COLUMN id SET DEFAULT nextval('inventory.product_warranties_id_seq'::regclass);


--
-- Name: products id; Type: DEFAULT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.products ALTER COLUMN id SET DEFAULT nextval('inventory.products_id_seq'::regclass);


--
-- Name: report_requests id; Type: DEFAULT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.report_requests ALTER COLUMN id SET DEFAULT nextval('inventory.report_requests_id_seq'::regclass);


--
-- Name: stock_entries id; Type: DEFAULT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.stock_entries ALTER COLUMN id SET DEFAULT nextval('inventory.stock_entries_id_seq'::regclass);


--
-- Name: stock_entry_items id; Type: DEFAULT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.stock_entry_items ALTER COLUMN id SET DEFAULT nextval('inventory.stock_entry_items_id_seq'::regclass);


--
-- Name: stock_transformation_lines id; Type: DEFAULT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.stock_transformation_lines ALTER COLUMN id SET DEFAULT nextval('inventory.stock_transformation_lines_id_seq'::regclass);


--
-- Name: stock_transformations id; Type: DEFAULT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.stock_transformations ALTER COLUMN id SET DEFAULT nextval('inventory.stock_transformations_id_seq'::regclass);


--
-- Name: warehouses id; Type: DEFAULT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.warehouses ALTER COLUMN id SET DEFAULT nextval('inventory.warehouses_id_seq'::regclass);


--
-- Name: detraccion_service_codes id; Type: DEFAULT; Schema: master; Owner: postgres
--

ALTER TABLE ONLY master.detraccion_service_codes ALTER COLUMN id SET DEFAULT nextval('master.detraccion_service_codes_id_seq'::regclass);


--
-- Name: failed_jobs id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.failed_jobs_id_seq'::regclass);


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- Name: users id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.users ALTER COLUMN id SET DEFAULT nextval('public.users_id_seq'::regclass);


--
-- Name: cash_movements id; Type: DEFAULT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.cash_movements ALTER COLUMN id SET DEFAULT nextval('sales.cash_movements_id_seq'::regclass);


--
-- Name: cash_registers id; Type: DEFAULT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.cash_registers ALTER COLUMN id SET DEFAULT nextval('sales.cash_registers_id_seq'::regclass);


--
-- Name: cash_sessions id; Type: DEFAULT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.cash_sessions ALTER COLUMN id SET DEFAULT nextval('sales.cash_sessions_id_seq'::regclass);


--
-- Name: commercial_document_item_lots id; Type: DEFAULT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.commercial_document_item_lots ALTER COLUMN id SET DEFAULT nextval('sales.commercial_document_item_lots_id_seq'::regclass);


--
-- Name: commercial_document_items id; Type: DEFAULT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.commercial_document_items ALTER COLUMN id SET DEFAULT nextval('sales.commercial_document_items_id_seq'::regclass);


--
-- Name: commercial_document_payments id; Type: DEFAULT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.commercial_document_payments ALTER COLUMN id SET DEFAULT nextval('sales.commercial_document_payments_id_seq'::regclass);


--
-- Name: commercial_documents id; Type: DEFAULT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.commercial_documents ALTER COLUMN id SET DEFAULT nextval('sales.commercial_documents_id_seq'::regclass);


--
-- Name: customer_price_profiles id; Type: DEFAULT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.customer_price_profiles ALTER COLUMN id SET DEFAULT nextval('sales.customer_price_profiles_id_seq'::regclass);


--
-- Name: customer_types id; Type: DEFAULT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.customer_types ALTER COLUMN id SET DEFAULT nextval('sales.customer_types_id_seq'::regclass);


--
-- Name: customers id; Type: DEFAULT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.customers ALTER COLUMN id SET DEFAULT nextval('sales.customers_id_seq'::regclass);


--
-- Name: document_sequences id; Type: DEFAULT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.document_sequences ALTER COLUMN id SET DEFAULT nextval('sales.document_sequences_id_seq'::regclass);


--
-- Name: order_sequences id; Type: DEFAULT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.order_sequences ALTER COLUMN id SET DEFAULT nextval('sales.order_sequences_id_seq'::regclass);


--
-- Name: price_tiers id; Type: DEFAULT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.price_tiers ALTER COLUMN id SET DEFAULT nextval('sales.price_tiers_id_seq'::regclass);


--
-- Name: product_price_tier_values id; Type: DEFAULT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.product_price_tier_values ALTER COLUMN id SET DEFAULT nextval('sales.product_price_tier_values_id_seq'::regclass);


--
-- Name: product_tier_prices id; Type: DEFAULT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.product_tier_prices ALTER COLUMN id SET DEFAULT nextval('sales.product_tier_prices_id_seq'::regclass);


--
-- Name: sales_order_item_lots id; Type: DEFAULT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.sales_order_item_lots ALTER COLUMN id SET DEFAULT nextval('sales.sales_order_item_lots_id_seq'::regclass);


--
-- Name: sales_order_items id; Type: DEFAULT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.sales_order_items ALTER COLUMN id SET DEFAULT nextval('sales.sales_order_items_id_seq'::regclass);


--
-- Name: sales_order_payments id; Type: DEFAULT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.sales_order_payments ALTER COLUMN id SET DEFAULT nextval('sales.sales_order_payments_id_seq'::regclass);


--
-- Name: sales_orders id; Type: DEFAULT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.sales_orders ALTER COLUMN id SET DEFAULT nextval('sales.sales_orders_id_seq'::regclass);


--
-- Name: series_numbers id; Type: DEFAULT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.series_numbers ALTER COLUMN id SET DEFAULT nextval('sales.series_numbers_id_seq'::regclass);


--
-- Data for Name: branch_feature_toggles; Type: TABLE DATA; Schema: appcfg; Owner: postgres
--

COPY appcfg.branch_feature_toggles (company_id, branch_id, feature_code, is_enabled, config, updated_by, updated_at) FROM stdin;
1	1	SALES_TAX_BRIDGE	t	{"codigolocal": null}	1	2026-04-04 10:38:29-05
\.


--
-- Data for Name: branch_modules; Type: TABLE DATA; Schema: appcfg; Owner: postgres
--

COPY appcfg.branch_modules (company_id, branch_id, module_id, is_enabled, config, updated_by, updated_at) FROM stdin;
\.


--
-- Data for Name: company_feature_toggles; Type: TABLE DATA; Schema: appcfg; Owner: postgres
--

COPY appcfg.company_feature_toggles (company_id, feature_code, is_enabled, config, updated_by, updated_at) FROM stdin;
1	PRODUCT_MULTI_UOM	f	\N	1	2026-04-04 10:38:28-05
1	PRODUCT_UOM_CONVERSIONS	t	\N	1	2026-04-04 10:38:28-05
1	PRODUCT_WHOLESALE_PRICING	t	\N	1	2026-04-04 10:38:28-05
1	INVENTORY_PRODUCTS_BY_PROFILE	t	\N	1	2026-04-04 10:38:28-05
1	INVENTORY_PRODUCT_MASTERS_BY_PROFILE	t	\N	1	2026-04-04 10:38:28-05
1	SALES_CUSTOMER_PRICE_PROFILE	t	\N	1	2026-04-04 10:38:28-05
1	SALES_SELLER_TO_CASHIER	f	\N	1	2026-04-04 10:38:28-05
1	SALES_ALLOW_ISSUED_EDIT_BEFORE_SUNAT_FINAL	f	\N	1	2026-04-04 10:38:28-05
1	SALES_ANTICIPO_ENABLED	f	\N	1	2026-04-04 10:38:28-05
1	SALES_TAX_BRIDGE	t	{"token": null, "beta_url": "https://mundosoftperu.com/MUNDOSOFTPERUSUNATBETA", "sol_pass": null, "sol_user": null, "envio_pse": null, "auth_scheme": "none", "bridge_mode": "BETA", "production_url": null, "timeout_seconds": 15, "auto_send_on_issue": true}	1	2026-04-04 10:38:28-05
1	SALES_DETRACCION_ENABLED	f	\N	1	2026-04-04 10:38:28-05
1	SALES_RETENCION_ENABLED	f	\N	1	2026-04-04 10:38:28-05
1	SALES_PERCEPCION_ENABLED	f	\N	1	2026-04-04 10:38:28-05
1	PURCHASES_DETRACCION_ENABLED	f	\N	1	2026-04-04 10:38:28-05
1	PURCHASES_RETENCION_COMPRADOR_ENABLED	f	\N	1	2026-04-04 10:38:28-05
1	PURCHASES_RETENCION_PROVEEDOR_ENABLED	f	\N	1	2026-04-04 10:38:28-05
1	PURCHASES_PERCEPCION_ENABLED	f	\N	1	2026-04-04 10:38:28-05
\.


--
-- Data for Name: company_modules; Type: TABLE DATA; Schema: appcfg; Owner: postgres
--

COPY appcfg.company_modules (company_id, module_id, is_enabled, is_mandatory, config, updated_by, updated_at) FROM stdin;
\.


--
-- Data for Name: company_role_profiles; Type: TABLE DATA; Schema: appcfg; Owner: postgres
--

COPY appcfg.company_role_profiles (company_id, role_id, functional_profile, updated_by, updated_at) FROM stdin;
1	3	\N	1	2026-03-26 05:30:32
\.


--
-- Data for Name: company_ui_field_settings; Type: TABLE DATA; Schema: appcfg; Owner: postgres
--

COPY appcfg.company_ui_field_settings (company_id, field_id, is_visible, is_required, is_editable, show_in_list, show_in_form, show_in_filters, updated_by, updated_at) FROM stdin;
\.


--
-- Data for Name: company_units; Type: TABLE DATA; Schema: appcfg; Owner: postgres
--

COPY appcfg.company_units (company_id, unit_id, is_enabled, updated_by, updated_at) FROM stdin;
1	2	f	1	2026-03-12 02:51:08
1	3	f	1	2026-03-12 02:51:08
1	1	f	1	2026-03-12 02:51:08
1	4	f	1	2026-03-12 02:51:08
1	5	f	1	2026-03-12 02:51:08
1	6	f	1	2026-03-12 02:51:08
1	7	f	1	2026-03-12 02:51:08
1	8	f	1	2026-03-12 02:51:08
1	9	f	1	2026-03-12 02:51:08
1	10	f	1	2026-03-12 02:51:08
1	11	f	1	2026-03-12 02:51:08
1	12	f	1	2026-03-12 02:51:08
1	13	f	1	2026-03-12 02:51:08
1	14	f	1	2026-03-12 02:51:08
1	15	f	1	2026-03-12 02:51:08
1	16	f	1	2026-03-12 02:51:08
1	17	f	1	2026-03-12 02:51:08
1	18	f	1	2026-03-12 02:51:08
1	19	f	1	2026-03-12 02:51:08
1	20	f	1	2026-03-12 02:51:08
1	21	f	1	2026-03-12 02:51:08
1	22	f	1	2026-03-12 02:51:08
1	23	f	1	2026-03-12 02:51:08
1	24	f	1	2026-03-12 02:51:08
1	25	f	1	2026-03-12 02:51:08
1	26	f	1	2026-03-12 02:51:08
1	27	f	1	2026-03-12 02:51:08
1	28	f	1	2026-03-12 02:51:08
1	29	f	1	2026-03-12 02:51:08
1	30	f	1	2026-03-12 02:51:08
1	31	f	1	2026-03-12 02:51:08
1	32	f	1	2026-03-12 02:51:08
1	33	f	1	2026-03-12 02:51:08
1	34	f	1	2026-03-12 02:51:08
1	35	f	1	2026-03-12 02:51:08
1	36	f	1	2026-03-12 02:51:08
1	37	f	1	2026-03-12 02:51:08
1	38	f	1	2026-03-12 02:51:08
1	39	f	1	2026-03-12 02:51:08
1	40	f	1	2026-03-12 02:51:08
1	41	f	1	2026-03-12 02:51:08
1	42	f	1	2026-03-12 02:51:08
1	43	f	1	2026-03-12 02:51:08
1	44	f	1	2026-03-12 02:51:08
1	45	f	1	2026-03-12 02:51:08
1	46	f	1	2026-03-12 02:51:08
1	47	f	1	2026-03-12 02:51:08
1	48	f	1	2026-03-12 02:51:08
1	49	f	1	2026-03-12 02:51:08
1	50	f	1	2026-03-12 02:51:08
1	51	f	1	2026-03-12 02:51:08
1	52	f	1	2026-03-12 02:51:08
1	53	f	1	2026-03-12 02:51:08
1	54	f	1	2026-03-12 02:51:08
1	55	f	1	2026-03-12 02:51:08
1	56	f	1	2026-03-12 02:51:08
1	57	f	1	2026-03-12 02:51:08
1	58	t	1	2026-03-12 02:51:08
1	59	t	1	2026-03-12 02:51:08
1	60	f	1	2026-03-12 02:51:08
1	63	f	1	2026-03-12 02:51:08
1	61	f	1	2026-03-12 02:51:08
1	62	f	1	2026-03-12 02:51:08
\.


--
-- Data for Name: modules; Type: TABLE DATA; Schema: appcfg; Owner: postgres
--

COPY appcfg.modules (id, code, name, description, is_core, status, created_at, updated_at) FROM stdin;
1	SALES	Ventas	Modulo de ventas	t	1	2026-03-10 21:21:33.764975-05	2026-03-10 21:21:33.764975-05
2	APPCFG	Configuracion	Modulo de configuracion	t	1	2026-03-10 21:21:33.764975-05	2026-03-10 21:21:33.764975-05
3	INVENTORY	Inventario	Modulo de inventario	t	1	2026-03-10 23:05:31.480601-05	2026-03-10 23:05:31.480601-05
\.


--
-- Data for Name: saved_filters; Type: TABLE DATA; Schema: appcfg; Owner: postgres
--

COPY appcfg.saved_filters (id, company_id, user_id, entity_id, name, is_public, filter_payload, sort_payload, status, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: ui_entities; Type: TABLE DATA; Schema: appcfg; Owner: postgres
--

COPY appcfg.ui_entities (id, module_id, code, name, route_path, status) FROM stdin;
\.


--
-- Data for Name: ui_fields; Type: TABLE DATA; Schema: appcfg; Owner: postgres
--

COPY appcfg.ui_fields (id, entity_id, code, label, data_type, default_visible, default_editable, default_filterable, display_order, config, status) FROM stdin;
\.


--
-- Data for Name: permissions; Type: TABLE DATA; Schema: auth; Owner: postgres
--

COPY auth.permissions (id, code, description) FROM stdin;
\.


--
-- Data for Name: refresh_tokens; Type: TABLE DATA; Schema: auth; Owner: postgres
--

COPY auth.refresh_tokens (id, user_id, token_hash, expires_at, revoked_at, created_at) FROM stdin;
1	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.34bb15a3221a65223281eb878163449bd21651542bfdde45a0e9857523f92f0d	2026-04-10 02:56:17-05	2026-03-11 02:58:24-05	2026-03-11 02:56:17-05
2	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.3e175d67be60e3aa8653b6d3567c7a3bf946d6fbe96a8d49a301f60473443b05	2026-04-10 02:58:24-05	2026-03-11 02:58:45-05	2026-03-11 02:58:24-05
3	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.4473234608771c02588d966eed378782178b39e38524024be03a9cae5fd6356d	2026-04-10 02:58:45-05	2026-03-11 03:01:01-05	2026-03-11 02:58:45-05
4	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.89c83412fae6254512f27367ea45eb3adffc04cf68d09aee3297fa35c9b592d5	2026-04-10 03:01:01-05	2026-03-11 03:01:31-05	2026-03-11 03:01:01-05
5	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.2814d407550a8b08ad143a3a788330d4c51006ab823de20286d2c60a5239004d	2026-04-10 03:01:31-05	2026-03-11 03:06:54-05	2026-03-11 03:01:31-05
6	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.bf4c86e6bb410d6c94ede3ece6edd30f5ec286c84961a547c1debc188096ff13	2026-04-10 03:06:54-05	2026-03-11 03:22:30-05	2026-03-11 03:06:54-05
7	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.ae7802b7d96490725c58bd7d8241f10cfb7272d21dcd1237139495a78229963c	2026-04-10 03:22:30-05	2026-03-11 03:44:31-05	2026-03-11 03:22:30-05
8	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.933c1cd259d9048007b1382f7726c38df3a6b94aa5b45000a982b423d5146efc	2026-04-10 03:44:31-05	2026-03-11 03:45:52-05	2026-03-11 03:44:31-05
9	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.fb61368be697bb5508272267753452ff9b0d36f6b3bffa05e60108b5e903c741	2026-04-10 03:45:52-05	2026-03-11 03:46:00-05	2026-03-11 03:45:52-05
10	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.f45e65b0abdece754a5de2b73f4f2583e15e2a6e2a984b3e4cc5725900e82a76	2026-04-10 03:48:08-05	2026-03-11 03:48:22-05	2026-03-11 03:48:08-05
11	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.06577ef2ed5ceb2c7c587da23fa6c156bf7831096694cf1529bf8c93b1e1a9c1	2026-04-10 03:55:49-05	2026-03-11 04:07:50-05	2026-03-11 03:55:49-05
12	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.6ad27bccc24560287cf21946f2a83e505a5a732186d24ee3fe4c00295a125e56	2026-04-10 04:07:50-05	2026-03-11 04:39:03-05	2026-03-11 04:07:50-05
13	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.319359e4bb7191c1a5afaf21142993b9a25faa9d6bdab0f8fd3066e7643355c3	2026-04-10 04:39:03-05	2026-03-11 04:39:04-05	2026-03-11 04:39:03-05
14	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.d2306073309a35b11e8889baaf0763957be6fc6b2934a9aa620d43a6ebd7548f	2026-04-10 04:39:04-05	2026-03-11 04:39:06-05	2026-03-11 04:39:04-05
15	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.000dba7300fa5fbf9b4041ac9710210766e8c26f0e144315124350929afb0740	2026-04-10 04:39:06-05	2026-03-11 04:39:07-05	2026-03-11 04:39:06-05
16	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.ea9b77f70c9c7132ca2de257ffd36e29f0c4378ee97b58827d85bcf5a32db98c	2026-04-10 04:39:07-05	2026-03-11 04:39:08-05	2026-03-11 04:39:07-05
17	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.59b71e4cabc3afa619a72f7e07e0eb96433695e0e053b43805602be6a152d7d4	2026-04-10 04:39:08-05	2026-03-11 04:39:09-05	2026-03-11 04:39:08-05
18	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.bd9d2c3317d5b9563976e563baa161cba2f899fbccdd55196a2e79df6a3313b9	2026-04-10 04:39:09-05	2026-03-11 04:39:10-05	2026-03-11 04:39:09-05
19	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.19eba8d15cc20bf2f15be19d34edab019bef47cf81f4a99d3e08f5fd4ca9c727	2026-04-10 04:39:10-05	2026-03-11 04:39:11-05	2026-03-11 04:39:10-05
20	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.a158316b0f2006fec0a1f46ac93fedff1eca78cc42496e10114e57864f685ec1	2026-04-10 04:39:11-05	2026-03-11 04:39:12-05	2026-03-11 04:39:11-05
21	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.be43d9c6a9085724c9cdcb8f2d1886f03fa6ec515f129dbcee04c50a5116d21c	2026-04-10 04:39:12-05	2026-03-11 04:39:13-05	2026-03-11 04:39:12-05
22	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.64a232a71f5901b7c5a65c2ab2355676f671dabde642c32b597e21ebb7a39f78	2026-04-10 04:39:13-05	2026-03-11 04:42:01-05	2026-03-11 04:39:13-05
23	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.6f5bc7073fce8a3ab92ba7fd7156c7eef80f219733526db2069a22b665007e67	2026-04-10 04:42:01-05	2026-03-11 04:42:05-05	2026-03-11 04:42:01-05
24	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.92f27fd7ecc0c328dfe13e975a5781b2065e9d481b565a83cade2951017fb8ff	2026-04-10 04:42:05-05	2026-03-11 04:58:34-05	2026-03-11 04:42:05-05
25	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.fde11a258c257e0c3c18e291fd71d73b0f298deb86c4028c6e99df96e68d4802	2026-04-10 04:58:34-05	2026-03-11 04:59:10-05	2026-03-11 04:58:34-05
26	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.0a3272358372e20be195d8f9c9d7876baa7f66545b0b2f24ac0ed0ab3fc24ef9	2026-04-10 04:59:10-05	2026-03-11 20:17:21-05	2026-03-11 04:59:10-05
27	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.4d2b317390fe14389d64a0b9b223ceb7e5acab61360c30665c1b0c59353de651	2026-04-10 20:17:21-05	2026-03-11 20:22:24-05	2026-03-11 20:17:21-05
28	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.49f7c023115d67a45fa26e4f814e665c81942209c1d1c171b4fd09bdcf4152a5	2026-04-10 20:22:24-05	2026-03-11 20:23:33-05	2026-03-11 20:22:24-05
29	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.a8623d473e3695f160ac03ff6773f3122c78026241e0166ffd441340791222d9	2026-04-10 20:23:33-05	2026-03-11 20:23:42-05	2026-03-11 20:23:33-05
30	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.b9d0342a9e1dddc99b87d47d01c6cf654f992d73032c7d1f658941cb61254b57	2026-04-10 20:23:42-05	2026-03-11 20:23:48-05	2026-03-11 20:23:42-05
31	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.b0fb86ea499462d89b578c4254b1bf5c24a1e7412e4721fa94e657ba22bb222d	2026-04-10 20:23:50-05	2026-03-11 20:25:06-05	2026-03-11 20:23:50-05
32	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.5465ddb191224a1a6e5db35b16ff25f964f15a0b9b83349af9ed1f30f40b7bdd	2026-04-10 20:25:07-05	2026-03-11 20:25:12-05	2026-03-11 20:25:07-05
33	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.40efecdbb5263cebdaf774bb4c32a63b176de000fbb81edbb49caf16f77bb1b7	2026-04-10 20:25:12-05	2026-03-11 20:30:12-05	2026-03-11 20:25:12-05
34	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.f4a2a2f5d6056ca5b8bf01d40c733fa3407687e99d090429cb187bac2c8cd8e5	2026-04-10 20:30:12-05	2026-03-11 20:52:41-05	2026-03-11 20:30:12-05
35	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.6baf9b3032b33e9dae95b1f5b76874a1854497cf91695a432c8911326b99004b	2026-04-10 20:52:41-05	2026-03-11 20:55:37-05	2026-03-11 20:52:41-05
36	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.6052b5d944ee6e43328cd0618dd49c4ae3888a7f1ce20bba4efbb83184412865	2026-04-10 20:55:37-05	2026-03-11 21:29:34-05	2026-03-11 20:55:37-05
37	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.5e8ffa5dcb5b2ff35788e45f80fd08fe3bd580ad1716760cb3d741b62026d72d	2026-04-10 21:29:34-05	2026-03-12 02:14:13-05	2026-03-11 21:29:34-05
38	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.ef1fd795c583e8dc8d0177d656a5529974e9a5cae2787f946a619a21271aef2f	2026-04-11 02:14:13-05	2026-03-12 02:45:09-05	2026-03-12 02:14:13-05
39	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.6387bcbe868dff476251a8ae436fddc794323e679e64ff0c4a0d371890ae5418	2026-04-11 02:45:09-05	2026-03-12 03:30:50-05	2026-03-12 02:45:09-05
40	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.d429048ea84ce11d47375b81c50f50967725b7b5a0de60dac979e94646003fd1	2026-04-11 03:30:50-05	2026-03-12 03:30:51-05	2026-03-12 03:30:50-05
41	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.49f94f68d015c8d2eb09d7ad2195a1f8e61a3b6ce7c74352b51141f825218416	2026-04-11 03:30:51-05	2026-03-12 03:30:52-05	2026-03-12 03:30:51-05
42	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.0c5831436aaa30f6fca64e8fb1bb23c18fc4971830fcbacc1849f4b9b8471f3d	2026-04-11 03:30:52-05	2026-03-12 03:31:58-05	2026-03-12 03:30:52-05
43	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.3fd719655fb2fcd4e2b0af01556252a449faada0f6dc0f9b5ab3c4a4bd9e6db3	2026-04-11 03:31:58-05	2026-03-12 03:32:05-05	2026-03-12 03:31:58-05
44	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.b34db7dbcfea6ed8f00b844f1fc6577fbc3cb4fb3713862ec75bff9071d5c1f4	2026-04-11 03:32:05-05	2026-03-12 04:02:07-05	2026-03-12 03:32:05-05
45	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.bb2ab9dc87db552d451aa04ee4966501411d9988b312488e574882b692fd9621	2026-04-11 04:02:07-05	2026-03-12 04:32:40-05	2026-03-12 04:02:07-05
46	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.f3a07654795e724b566f18cb4c7baa426b0b934e5ef43dcb49fcda6adcdf5d66	2026-04-11 04:32:40-05	2026-03-12 05:20:48-05	2026-03-12 04:32:40-05
47	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.33894aa9c6ab1a2e8991dcdccac69e19312cf9acd4388cae54b25681b4af2af3	2026-04-11 05:20:48-05	2026-03-12 05:20:48-05	2026-03-12 05:20:48-05
48	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.23cd5473e9e0a39125a0bc360ddd2b3c26d3069c5d6b2a413c867103bb0427c0	2026-04-11 05:20:48-05	2026-03-12 05:20:49-05	2026-03-12 05:20:48-05
49	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.d8ae366e1ec7c4074c47883245fca67060e1729927387b609505994b76cb9263	2026-04-11 05:20:49-05	2026-03-12 05:20:51-05	2026-03-12 05:20:49-05
50	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.25d4b99ee6bb7cabf797872579b26673689eb296ae6ddb84e29bde8796d066b1	2026-04-11 05:20:51-05	2026-03-12 05:22:49-05	2026-03-12 05:20:51-05
51	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.469c257896e4ceb7b1d64ea22d01dd432a37d034adc5bb9049d3b9e687348d7a	2026-04-11 05:22:49-05	2026-03-14 13:22:14-05	2026-03-12 05:22:49-05
52	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.cc49d59e6b6c6c0a85c3439f11ca03b022eff52b6793e6c920094c985e417ef2	2026-04-13 13:22:14-05	2026-03-14 13:36:23-05	2026-03-14 13:22:14-05
53	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.585c6a61dce1df633c1ebc8dc5e79281735d0034ef00b499d1943ece198de2a7	2026-04-13 13:36:23-05	2026-03-14 13:38:25-05	2026-03-14 13:36:23-05
54	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.939e0860fa48543c9147a2cb1cc6a4650c03527f55662e3e1012b0a901947605	2026-04-13 13:38:25-05	2026-03-14 14:10:26-05	2026-03-14 13:38:25-05
55	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.908b04701dd3dc394241a5eed0d9f8703a56d4e2811232ab3151426f9527299c	2026-04-13 14:10:26-05	2026-03-14 14:10:27-05	2026-03-14 14:10:26-05
56	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.2d340d4b69c43999e841a6f9580c612d4f5954cad24d22e205a01cf1bc3e3985	2026-04-13 14:10:27-05	2026-03-14 18:10:26-05	2026-03-14 14:10:27-05
57	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.5e21617af3adf994f405575e842f57f96acd40104e47edcfbb922e776effbe9f	2026-04-13 18:10:26-05	2026-03-14 18:10:33-05	2026-03-14 18:10:26-05
58	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.513c29855518cb473d88462ab4ac0f13d75cf6174dbc2a74cc66013fd4f96cc6	2026-04-13 18:10:33-05	2026-03-14 23:34:43-05	2026-03-14 18:10:33-05
59	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.4589eb0eaef25c4035483a4244ae98a2a363aa1d8d0eadc52b973a116ae66779	2026-04-13 23:34:43-05	2026-03-14 23:34:44-05	2026-03-14 23:34:43-05
60	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.b9036d88630e12cf1e6e997f5901e4c7ae4c5c947d282c452fd48c469f3d945b	2026-04-13 23:34:44-05	2026-03-14 23:34:45-05	2026-03-14 23:34:44-05
61	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.8bc44c180c0faa9619158c78526fd2cbd14f21d5aed93343bccc865180da277d	2026-04-13 23:34:45-05	2026-03-14 23:36:04-05	2026-03-14 23:34:45-05
62	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.e272ced4a2d1964e296562cace2c9637141c74ae9086b7512f27e407667ccaf0	2026-04-13 23:36:04-05	2026-03-14 23:36:08-05	2026-03-14 23:36:04-05
63	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.f50936f2b3a0c5b8ad5a0e475a0c1b178bdf4636fed04fc1c8780ce632fdb648	2026-04-13 23:36:08-05	2026-03-15 00:07:31-05	2026-03-14 23:36:08-05
64	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.c57b032f0f90a3e070c5a99d42c26a0ca82fd5858e2251a0a98b710bc51b627c	2026-04-14 00:07:31-05	2026-03-15 00:07:32-05	2026-03-15 00:07:31-05
65	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.d488c78ad402fc91d78e0ecea0011f0a6b14dcab6e60923ce4682dc8011cf55a	2026-04-14 00:07:32-05	2026-03-15 00:07:34-05	2026-03-15 00:07:32-05
66	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.fe328810e4b83802e379cc26af7c55067d344209b2502c20cac988a6e88ba4ca	2026-04-14 00:07:34-05	2026-03-15 00:07:35-05	2026-03-15 00:07:34-05
67	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.9caf44f04230bd588466b603f0aedb2c99d2da6d8a29ad70f77ddc12d65efac0	2026-04-14 00:07:35-05	2026-03-15 00:08:31-05	2026-03-15 00:07:35-05
68	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.76774ea56816eb95ff0bec874c2b0cc437894c5a3931cced18f826f9d36f704e	2026-04-14 00:08:31-05	2026-03-17 18:43:58-05	2026-03-15 00:08:31-05
69	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.74d2efbca669b95abebb434e72ca9b41bfba608a2fc56059592b71a6c00984b3	2026-04-16 18:43:58-05	2026-03-17 18:43:59-05	2026-03-17 18:43:58-05
70	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.f1ee520cbd6d64dea114720afa25b370444e8edd2f7a2f730d0873df06660382	2026-04-16 18:43:59-05	2026-03-17 18:44:00-05	2026-03-17 18:43:59-05
71	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.70cb018da0d02a46602bc22cf1eb85b0a801368a2117c745178987379111a9ec	2026-04-16 18:44:00-05	2026-03-17 18:45:24-05	2026-03-17 18:44:00-05
72	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.1d1b9d0dc92b9fc6c3733def57588924b680b00692d86990d839ab4a63dfd4b9	2026-04-16 18:45:24-05	2026-03-17 18:52:02-05	2026-03-17 18:45:24-05
73	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.aa228a3939463a72b98419b57afe16b63c19d5dddd0785426cc38a2b49816049	2026-04-16 18:52:04-05	2026-03-17 19:26:14-05	2026-03-17 18:52:04-05
74	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.f3dc25b4a3ba56840fe09de6b816ed7e20d2c2270ac47238a949e2becf5799b6	2026-04-16 19:26:14-05	2026-03-17 19:26:15-05	2026-03-17 19:26:14-05
75	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.debb8d95d1359723c6d128af3379072d6ee08b4aa42fe5d2deb2d460a892fb66	2026-04-16 19:26:15-05	2026-03-17 19:26:16-05	2026-03-17 19:26:15-05
76	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.0618a0ee38a6dcdba4f4f18f0aaab48868575d4ef067d565f96847e97925a55d	2026-04-16 19:26:16-05	2026-03-17 19:26:17-05	2026-03-17 19:26:16-05
77	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.6a777389fee848ded4252dac95aedb3e74c02857fea72758e5bbd35f4dc1f422	2026-04-16 19:26:17-05	2026-03-17 19:27:13-05	2026-03-17 19:26:17-05
78	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.53e8e1081e90376c38ceb435e97b2917901f5de9a88052feea2a791391f2a747	2026-04-16 19:27:13-05	2026-03-17 20:20:32-05	2026-03-17 19:27:13-05
79	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.8451a584335ea8b27a7167ef624f6d7483699492f87fe07cd0a2538847952f1c	2026-04-16 20:20:32-05	2026-03-17 20:20:33-05	2026-03-17 20:20:32-05
80	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.47dae0b5b00be4cd794f245c5cd30edae48d3fda648218444f5f10705ab84a57	2026-04-16 20:20:33-05	2026-03-17 20:20:35-05	2026-03-17 20:20:33-05
81	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.940c1e8fdbec969020f3bab9041984d34340871d9312d764f8083a0e1ddbf7f2	2026-04-16 20:20:35-05	2026-03-17 20:22:03-05	2026-03-17 20:20:35-05
82	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.7084431e33717d90af1f06cb6a8728c0d89feaadeda3d44fcd7114e4d41b7244	2026-04-16 20:22:03-05	2026-03-17 22:43:57-05	2026-03-17 20:22:03-05
83	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.11181c7069f6b7d79fd2e24ec0bfab5f13197f6c2fa5c3df42b2af14c1aa62b8	2026-04-16 22:43:57-05	2026-03-19 03:13:52-05	2026-03-17 22:43:57-05
84	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.254a8c46d6d3bc7948bebfa0399dadaca451f44e7f808fa0e716a059624532ce	2026-04-18 03:13:52-05	2026-03-19 03:13:53-05	2026-03-19 03:13:52-05
85	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.2a7f8644adc1717e6a8de3ce919b592e6213cc391e23db9bb759efeef1532f1a	2026-04-18 03:13:53-05	2026-03-19 03:13:54-05	2026-03-19 03:13:53-05
86	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.b3967e1dfccddaedfee440e9f097d83924985cc6a9a9425ab656237baa56bc8e	2026-04-18 03:13:54-05	2026-03-19 03:15:26-05	2026-03-19 03:13:54-05
87	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.9b80a79897de71f39bdd4593ff5055d1f16a46203b919049ad6b5e7511c8fd3b	2026-04-18 03:15:26-05	2026-03-19 03:50:50-05	2026-03-19 03:15:26-05
88	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.78d77ac7fff5c7d29de1159fbe3174e1d561589f05528cccfe05cbd4c76d5771	2026-04-18 03:50:50-05	2026-03-19 04:20:53-05	2026-03-19 03:50:50-05
89	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.e70276d875776140a35cd67442c749bdd4c53745176f6d9d1441c5e347b9486d	2026-04-18 04:20:53-05	2026-03-19 04:20:54-05	2026-03-19 04:20:53-05
90	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.299d420f5aeb679f26a49f1438ebb68a937651542def5d0389213c007ff61d95	2026-04-18 04:20:54-05	2026-03-19 04:20:55-05	2026-03-19 04:20:54-05
91	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.994dbbf67beda0a0b463640c0c4e358fe203b2e5b72bf4b2044c779e12223194	2026-04-18 04:20:55-05	2026-03-19 04:20:56-05	2026-03-19 04:20:55-05
92	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.5b96cd21508af3667b11e3e518be822292ea96c4d0f900a6d75895a2a9dc4464	2026-04-18 04:20:56-05	2026-03-19 04:20:57-05	2026-03-19 04:20:56-05
93	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.facf6e834dc848f3ec440b2cb068384d397a683bbf5f6978bf72ecf6fa81a8b6	2026-04-18 04:20:57-05	2026-03-19 04:20:59-05	2026-03-19 04:20:57-05
94	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.c42f8bcaa3a39b799017f06d9a25cdf2efa5bd10b3eae42b57b84b6967d59f5f	2026-04-18 04:20:59-05	2026-03-19 04:21:00-05	2026-03-19 04:20:59-05
95	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.7fd1d583cc09d81e810bdd4909daed82eef7e6307beb140dfda7d692a20ee175	2026-04-18 04:21:00-05	2026-03-19 04:21:02-05	2026-03-19 04:21:00-05
96	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.46326188af59c827d9c440aa531f7b21b8c613ab8bbddc84cf2ced53fa18c093	2026-04-18 04:21:02-05	2026-03-19 04:22:14-05	2026-03-19 04:21:02-05
97	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.7cc0162ba6f95d2263318d490bb5a3643c6b982af8a7bfed230530558771b7cc	2026-04-18 04:22:16-05	2026-03-19 04:52:24-05	2026-03-19 04:22:16-05
98	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.20dcf743b84d2d9648e917470b6467ff06a3cd8679522eba91536fae87f855d9	2026-04-18 04:52:24-05	2026-03-19 04:52:25-05	2026-03-19 04:52:24-05
99	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.81b5a9b9e5f0ada179f13d211861ea2c76a3ba6e73dd047985b9f9eac3280288	2026-04-18 04:52:25-05	2026-03-19 04:52:36-05	2026-03-19 04:52:25-05
100	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.a6da990f7b75cf55de8f034cc03eb63da2c169a7428d0beac3e4274cf85a6e78	2026-04-18 04:52:36-05	2026-03-19 04:52:37-05	2026-03-19 04:52:36-05
101	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.d5e8c9fc6356a60557710dabcbd2241204e6a8e8da6eaab4b6628ca172289645	2026-04-18 04:52:37-05	2026-03-19 04:52:48-05	2026-03-19 04:52:37-05
102	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.fd0bf7ba537f6a1e271eb582201f56b0f1302b77c6a153a5cde942860b8b1f6d	2026-04-18 04:52:48-05	2026-03-19 04:52:49-05	2026-03-19 04:52:48-05
103	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.4d26cbcf695053e576971e3aee239c2e347b8d32159a6952d5cb4d24adb9cdf0	2026-04-18 04:52:49-05	2026-03-19 04:53:00-05	2026-03-19 04:52:49-05
104	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.b6e5b04923d3002da26b1698cb98d0656d37ad611a203dcedbf362cc6eae1c30	2026-04-18 04:53:00-05	2026-03-19 04:53:01-05	2026-03-19 04:53:00-05
105	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.e1d534d3847cf7f6d5ccf8ac8fe9998bb7f9069868d1e428a9ee8214c8faafb0	2026-04-18 04:53:01-05	2026-03-19 04:53:12-05	2026-03-19 04:53:01-05
106	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.59ae758f8d627e18a2d151fe2df6caedd36daeacc213bff8821212cf1b73084b	2026-04-18 04:53:12-05	2026-03-19 04:53:13-05	2026-03-19 04:53:12-05
107	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.8af8f4518ad8f2ea1af8b664e50a32d43d04fed81e033dd1ae44bc7c96a78fa3	2026-04-18 04:53:13-05	2026-03-19 04:53:24-05	2026-03-19 04:53:13-05
108	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.4b532d2021acab16f75ae9da107e4641667030e882ade07b71aa2492df9d93c0	2026-04-18 04:53:24-05	2026-03-19 04:53:25-05	2026-03-19 04:53:24-05
109	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.2ed8d3f8cd72039770bf5d7ac546c6949fc354c82e64a135de2c5f141d2b4290	2026-04-18 04:53:25-05	2026-03-19 04:53:36-05	2026-03-19 04:53:25-05
110	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.e4e8609d1214667b82b926f56887f9b0be4fc8a2edd7cbe5581ec45942e488ec	2026-04-18 04:53:36-05	2026-03-19 04:53:37-05	2026-03-19 04:53:36-05
111	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.839eaf56094e4e35c4a2531e4e1574c917163c3de55ff267ca10d827d9d7012e	2026-04-18 04:53:37-05	2026-03-19 04:53:48-05	2026-03-19 04:53:37-05
112	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.dae847a990628f70e6679c15295f333dd5cf3aca311e6c6447af04542fd1616d	2026-04-18 04:53:48-05	2026-03-19 04:53:49-05	2026-03-19 04:53:48-05
113	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.86afa1495c3b190edab5ebe287f4ed9f9d5c6721720434cd14e4fb32ca42f8a3	2026-04-18 04:53:49-05	2026-03-19 04:54:00-05	2026-03-19 04:53:49-05
114	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.292e42cb24dc7b8a4136fdc6f677a644140480a0e35c63fb7567d8294004aa3d	2026-04-18 04:54:00-05	2026-03-19 04:54:01-05	2026-03-19 04:54:00-05
115	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.b742c0534460a7cfa7d84bf0ffa2b902b1a94c21e01fe249f5e9123583395683	2026-04-18 04:54:01-05	2026-03-19 04:54:12-05	2026-03-19 04:54:01-05
116	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.357c2bf325bc9039f4357852a547878f286d20a6b3f471685bc17e32d92bfab2	2026-04-18 04:54:12-05	2026-03-19 04:54:13-05	2026-03-19 04:54:12-05
117	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.6f20150f5cca9d7149e00a40c11d67a1ffd3c5239f44ae8d53cb5a5f3aa52d3f	2026-04-18 04:54:13-05	2026-03-19 04:54:24-05	2026-03-19 04:54:13-05
118	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.3d8f249a1a6f967a1e564686ae3554d36009db493c8dc545396159cf28be3b82	2026-04-18 04:54:24-05	2026-03-19 04:54:25-05	2026-03-19 04:54:24-05
119	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.45e0d11599e989fd1c42b6c92bf7603ddadcaac811cb5aa8b165a457586e78e0	2026-04-18 04:54:25-05	2026-03-19 04:54:36-05	2026-03-19 04:54:25-05
120	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.88547d48558615d9272702e4b98705c6df8e26c6ce7bc54e9cf32a0b7b6bc619	2026-04-18 04:54:36-05	2026-03-19 04:54:37-05	2026-03-19 04:54:36-05
121	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.06d64c6b0e30f395c47cb36f994096628145aac63942e6e9652e4fb04883dde4	2026-04-18 04:54:37-05	2026-03-19 04:54:48-05	2026-03-19 04:54:37-05
122	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.bb6fb5cf6a2fbde27bbe6f3a4c2199508dd88b802aefd3e91064e27af8d5be06	2026-04-18 04:54:48-05	2026-03-19 04:54:49-05	2026-03-19 04:54:48-05
123	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.2168b9599a3751da97753022f736c8b337fc744a0316623712ab63a81bf1e824	2026-04-18 04:54:49-05	2026-03-19 04:55:00-05	2026-03-19 04:54:49-05
124	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.e614bb91e27f7fcc1bf246140b3590ca5613cee66b416ad124ed468bf8fb4eb0	2026-04-18 04:55:00-05	2026-03-19 04:55:01-05	2026-03-19 04:55:00-05
125	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.6b84ff7848e392b692ea7ec67b3459c02cf79e504e56d8f549d2217876c52e3c	2026-04-18 04:55:01-05	2026-03-19 04:55:12-05	2026-03-19 04:55:01-05
126	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.75a179982eb413ca8260463bfd5c3d7ab51d38a6b8c93efdb4be60045764d00c	2026-04-18 04:55:12-05	2026-03-19 04:55:13-05	2026-03-19 04:55:12-05
127	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.197fc9b62f0d68a8d52c2c6ac4932cbeaff5128252078b164d4e066030b56f34	2026-04-18 04:55:13-05	2026-03-19 04:55:24-05	2026-03-19 04:55:13-05
128	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.ab8632a5ca9c8ab2f7f97ebbc0dacbddb3e590ccc1dec96af983d8d3ee3100d7	2026-04-18 04:55:24-05	2026-03-19 04:55:25-05	2026-03-19 04:55:24-05
129	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.a36e8857802685d7088795f6c31df514fc00fd914f2c152385662c3152ae93a3	2026-04-18 04:55:25-05	2026-03-19 04:55:36-05	2026-03-19 04:55:25-05
130	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.5b29ff065455a7412e0801222b466cbe9edc7139e837438088d2e4f29db6c335	2026-04-18 04:55:36-05	2026-03-19 04:55:37-05	2026-03-19 04:55:36-05
131	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.a094b1c0864e4613bfebfc03589b65878a24f3e85365dd41d562a2ae0fe64c9e	2026-04-18 04:55:37-05	2026-03-19 04:55:48-05	2026-03-19 04:55:37-05
132	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.22161997c02f1942c96f62719f1aea97e45e18e382ae060cb57cf7a6b4f85746	2026-04-18 04:55:48-05	2026-03-19 04:55:49-05	2026-03-19 04:55:48-05
133	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.8d4a20141f63ade46466df68c5ed1a00ba33dda563ee211c6e2a06692f56fb3e	2026-04-18 04:55:49-05	2026-03-19 04:56:00-05	2026-03-19 04:55:49-05
134	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.710c98c3da3151218230304dd77503c04e6f8d5eb1db0ead6c389e5b9bad5caa	2026-04-18 04:56:00-05	2026-03-19 04:56:01-05	2026-03-19 04:56:00-05
135	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.431dd0cf148ff8274f006fb035446af24f77eaa9944ef9c54a91c4bedac698f6	2026-04-18 04:56:01-05	2026-03-19 04:56:12-05	2026-03-19 04:56:01-05
136	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.d72e9dd004c58f6381327cf156736b9a7b12d32b2855e61157c4594f58b7bbe1	2026-04-18 04:56:12-05	2026-03-19 04:56:13-05	2026-03-19 04:56:12-05
137	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.4968eee4f1aa1fcea211a5c492aa1d94d4e422cceeeef6c52f701da85727092b	2026-04-18 04:56:13-05	2026-03-19 04:56:24-05	2026-03-19 04:56:13-05
138	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.3a4ca8f1926c5656291a8f0c18b7c0cde0018df080ff404e8ffb94e44917323b	2026-04-18 04:56:24-05	2026-03-19 04:56:25-05	2026-03-19 04:56:24-05
139	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.e20daceca5b0210563b2fe9f6cf2d1bba2e10162fb45d98f7942b45261fff0b3	2026-04-18 04:56:25-05	2026-03-19 04:56:36-05	2026-03-19 04:56:25-05
140	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.627e6b4b93e1051d8cbacd016f6d5ed88afd70d354f4b58d3216dc1a0fba7aad	2026-04-18 04:56:36-05	2026-03-19 04:56:37-05	2026-03-19 04:56:36-05
141	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.d3996d9157c5a3b6c3767087af8cdd25aca626e237d2af77bd017deed298d404	2026-04-18 04:56:37-05	2026-03-19 04:56:48-05	2026-03-19 04:56:37-05
142	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.4b6b2fe0b9e9e95a881302c857b658e5d56c94067f85007519fbcbfa1ecc6a91	2026-04-18 04:56:48-05	2026-03-19 04:56:49-05	2026-03-19 04:56:48-05
143	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.6b35c1fbe0e8fd59fd12ce1565c8a1f0e2843616f3d80afa16c7a3607e03ff63	2026-04-18 04:56:49-05	2026-03-19 04:57:00-05	2026-03-19 04:56:49-05
144	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.428e25a481d74fe5725d73669c42adba7c2651722476646b0cb1fa9003f9ec68	2026-04-18 04:57:00-05	2026-03-19 04:57:01-05	2026-03-19 04:57:00-05
145	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.9ab77f5ba78830e0c3a7760b585416d22cca40849fe0eb0389b9717818d82eff	2026-04-18 04:57:01-05	2026-03-19 04:57:12-05	2026-03-19 04:57:01-05
146	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.85c359d8668fb99219290abb229d66b09c14904d9c17da8719fbe0cabb4154df	2026-04-18 04:57:12-05	2026-03-19 04:57:13-05	2026-03-19 04:57:12-05
147	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.f5054f6219ca154207b1aacb4d07404ff3174f863d393882e75199209f541bbb	2026-04-18 04:57:13-05	2026-03-19 04:57:24-05	2026-03-19 04:57:13-05
148	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.6e5646ba05b179e45538d0607af8a54d856620636eca66472bffd7dd780657be	2026-04-18 04:57:24-05	2026-03-19 04:57:25-05	2026-03-19 04:57:24-05
149	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.cec520688976f764ddc9113f87911c883751cab4d3ec4ac8bfa4dc43c5e32916	2026-04-18 04:57:25-05	2026-03-19 04:57:36-05	2026-03-19 04:57:25-05
150	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.956750e6d7714b00d5f4229292d66d7a0bd9dcf4b2a24de4e42d452c8f58d303	2026-04-18 04:57:36-05	2026-03-19 04:57:37-05	2026-03-19 04:57:36-05
151	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.36a8f7a861c5451a2eefc3fbe173a7dced4265150eb2690d5775d16e3e773d34	2026-04-18 04:57:37-05	2026-03-19 04:57:48-05	2026-03-19 04:57:37-05
152	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.6f0937667af58bbababcd51aa12cbe4db95c86202a9a3b2d19b7c3eb9662eeb3	2026-04-18 04:57:48-05	2026-03-19 04:57:49-05	2026-03-19 04:57:48-05
153	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.b8de50b9becd7398e154ba8993c7207b60c9a746932457e4b9a2f6efe6467c9a	2026-04-18 04:57:49-05	2026-03-19 04:58:00-05	2026-03-19 04:57:49-05
154	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.ef4cb5851903cabd98db05fcf73b67957084393b6dd2a7528294e7d5ac6f6bc1	2026-04-18 04:58:00-05	2026-03-19 04:58:01-05	2026-03-19 04:58:00-05
155	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.69f9420db7a7ddce4d6fcacff3322ef0ee60fd5ccd2ed111c5e74df33e418ce6	2026-04-18 04:58:01-05	2026-03-19 04:58:12-05	2026-03-19 04:58:01-05
156	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.8640cfcb3e87ff523d90289b6dcd67f382494af870ec6a8e2223d4112f43c92d	2026-04-18 04:58:12-05	2026-03-19 04:58:13-05	2026-03-19 04:58:12-05
157	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.5a62054b95569576ffed70b30d4a4f3e98630a7e03ab9d410dea62c1e442ffa7	2026-04-18 04:58:13-05	2026-03-19 04:58:24-05	2026-03-19 04:58:13-05
158	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.6971d2fd2447e68336d3fa52b8cdcba5b27a2a163ddb334573cd794326c198e4	2026-04-18 04:58:24-05	2026-03-19 04:58:25-05	2026-03-19 04:58:24-05
159	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.ca603569b5eb2fe03cb68533644b18135759a0346fa319df4893c562a500e441	2026-04-18 04:58:25-05	2026-03-19 04:58:36-05	2026-03-19 04:58:25-05
160	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.d5bfc52df08f022a8831748e37edede3784bf8d59a5847ad088ea744b9883a3e	2026-04-18 04:58:36-05	2026-03-19 04:58:37-05	2026-03-19 04:58:36-05
161	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.073eb2c26efb27aea34db068af7300f91781dfce77ecfb3e55fb13d5af8d5be4	2026-04-18 04:58:37-05	2026-03-19 04:58:48-05	2026-03-19 04:58:37-05
162	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.83b2a23e87b6df7e33b68a929fbd31081737b63680d7c605bd8ae5e2e560185a	2026-04-18 04:58:48-05	2026-03-19 04:58:49-05	2026-03-19 04:58:48-05
163	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.3c6c95b8ef3ba724ae74319b543d241380fbc8cb9d95f7fed6a74b34c9214e1a	2026-04-18 04:58:49-05	2026-03-19 04:59:00-05	2026-03-19 04:58:49-05
164	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.e7740250845e6c2eae1df4379156383e14c5f09014ffec873f5a97a2d5195787	2026-04-18 04:59:00-05	2026-03-19 04:59:01-05	2026-03-19 04:59:00-05
165	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.dc62edcfb88d3a0432b130a3bd8697ffc5228541f2850badb32e20758fa4accf	2026-04-18 04:59:01-05	2026-03-19 04:59:12-05	2026-03-19 04:59:01-05
166	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.a1fb376f93272de602495b5b9abe3f6a8d8669cd9e06f3f33bf7d04f6fa35264	2026-04-18 04:59:12-05	2026-03-19 04:59:13-05	2026-03-19 04:59:12-05
167	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.077fa087ac314d4ebca42d1277c6bf27cf6e0f2e9e4de2bf7bf3aff1e148f981	2026-04-18 04:59:13-05	2026-03-19 04:59:24-05	2026-03-19 04:59:13-05
168	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.761f744607965ba91c1d70f0d23391152827eeeff1309ea101cee8b5a971bace	2026-04-18 04:59:24-05	2026-03-19 04:59:25-05	2026-03-19 04:59:24-05
169	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.acc8c4ecab33ca2c04a9e4c60638bcc6ccd46ca8583207764c3ed56b4da643b4	2026-04-18 04:59:25-05	2026-03-19 04:59:36-05	2026-03-19 04:59:25-05
170	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.35a3e577aa260f5629c880fc7cfe3f40ac4b8b941e66fa68258d8c7525423b46	2026-04-18 04:59:36-05	2026-03-19 04:59:37-05	2026-03-19 04:59:36-05
171	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.8f49ca937684d34032f4b00b63222b98aa182203ed8e139d7a2c8b9fa65edd33	2026-04-18 04:59:37-05	2026-03-19 04:59:48-05	2026-03-19 04:59:37-05
172	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.00716f03602b062c4a79ffe72baaed35560f2454bbcbfd8be97f11d895f31af4	2026-04-18 04:59:48-05	2026-03-19 04:59:49-05	2026-03-19 04:59:48-05
173	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.109604e71c0889811080b75d2cff96d31b368f6c80126feb9100438ad72423a0	2026-04-18 04:59:49-05	2026-03-19 05:00:00-05	2026-03-19 04:59:49-05
174	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.e4ad169326016823403a409375b0f1fac49e4200c79a631bd27961a9ecfa7a9d	2026-04-18 05:00:00-05	2026-03-19 05:00:01-05	2026-03-19 05:00:00-05
175	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.3eb7896adf7e2c401bd375f40ad90dd273dbd323c50ae63a23ec6695cb40d48b	2026-04-18 05:00:01-05	2026-03-19 05:00:12-05	2026-03-19 05:00:01-05
176	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.56a896dae4afed9b2b6543713a6887a1cc432e451a93ca7182c8aae38b958e11	2026-04-18 05:00:12-05	2026-03-19 05:00:13-05	2026-03-19 05:00:12-05
177	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.e2d651d3faf6b15b0e6042170650d07128fcf634bb925b33ed5b7da398c6d1a5	2026-04-18 05:00:13-05	2026-03-19 05:00:24-05	2026-03-19 05:00:13-05
178	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.9fc9a1dd8a7676e4e287871b955fb77b2852f18330d97233f55b22a3d5f5c978	2026-04-18 05:00:24-05	2026-03-19 05:00:25-05	2026-03-19 05:00:24-05
179	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.4731ab11c52711867265491ec90602f313869a7e0b416c4a495670c142c85448	2026-04-18 05:00:25-05	2026-03-19 05:00:36-05	2026-03-19 05:00:25-05
180	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.4e739e77c718c597e98b3d00b1085df96c64552119d92ed3320c26a5cb0e428f	2026-04-18 05:00:36-05	2026-03-19 05:00:37-05	2026-03-19 05:00:36-05
181	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.1f9520ba7d78c4eb5ae01e4bdac75e83a6d1b5629cef2f4de2edf5464068dce7	2026-04-18 05:00:37-05	2026-03-19 05:00:48-05	2026-03-19 05:00:37-05
182	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.ee6ccb61533f82b23942e4d8d4f117f7c7b6e86203766999866f8409af4ebfae	2026-04-18 05:00:48-05	2026-03-19 05:00:49-05	2026-03-19 05:00:48-05
183	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.bb8a57584ef30452d78a8ef68da425af2b874b59a1d57067b293dc8f5ae635a8	2026-04-18 05:00:49-05	2026-03-19 05:01:00-05	2026-03-19 05:00:49-05
184	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.7bf87e1494c1cb1cef489e7f0ddb261cb2835ab6f5482207adee47c487268f37	2026-04-18 05:01:00-05	2026-03-19 05:01:01-05	2026-03-19 05:01:00-05
185	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.76a834e071de43ceac2db985efc2b41c191b153bbcd01a334f05ec58c3618310	2026-04-18 05:01:01-05	2026-03-19 05:01:12-05	2026-03-19 05:01:01-05
186	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.9f00de6fbe7be56866cea9872fa8001cb2acdcfacefdf03d8995ec84525acc8b	2026-04-18 05:01:12-05	2026-03-19 05:01:13-05	2026-03-19 05:01:12-05
187	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.6296239afa954683ea9519115a7909a515f8921dd4b741cffffa0f84e5d86004	2026-04-18 05:01:13-05	2026-03-19 05:01:24-05	2026-03-19 05:01:13-05
188	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.7117eaa8c36d033e5e5f1efb36049e71f9a93e5884726dabf84df494c0aaab6d	2026-04-18 05:01:24-05	2026-03-19 05:01:25-05	2026-03-19 05:01:24-05
189	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.3687609d77767da45bd9cb2589acca585c7a8a4f002f34b35179eabcd58658e0	2026-04-18 05:01:25-05	2026-03-19 05:01:36-05	2026-03-19 05:01:25-05
190	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.dbcf7466907d6881f2704abeb2efb41b47c1bd630aff11b47bb4976e688e3953	2026-04-18 05:01:36-05	2026-03-19 05:01:37-05	2026-03-19 05:01:36-05
191	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.243be4d416d83c6cd08ef57fa823abba1fe6de21fa0e7973c594c0ab6ff12d85	2026-04-18 05:01:37-05	2026-03-19 05:01:48-05	2026-03-19 05:01:37-05
192	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.987391986387659afacf86ab00b7120e3e6ae41c2a5021efe77ae6e7e6a50b5b	2026-04-18 05:01:48-05	2026-03-19 05:01:49-05	2026-03-19 05:01:48-05
193	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.11237ad2f22606ea3388dce82cb381823acc1658e5952399e39d861cdf9c3e36	2026-04-18 05:01:49-05	2026-03-19 05:02:00-05	2026-03-19 05:01:49-05
194	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.e9a1b49bb5da10145032682db5c1670ac7265c4989484bfbad1857b44b828dda	2026-04-18 05:02:00-05	2026-03-19 05:02:01-05	2026-03-19 05:02:00-05
195	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.d87d07d762562cb70795e10494e3ac7f8c48574a8b4ea0ff2266d41cd69984c2	2026-04-18 05:02:01-05	2026-03-19 05:02:12-05	2026-03-19 05:02:01-05
196	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.42eaf499c94e9a8f3f473e31bbecd30bafadc70f451430794a1ad961341c0726	2026-04-18 05:02:12-05	2026-03-19 05:02:13-05	2026-03-19 05:02:12-05
197	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.95713b888e064fefd78493207a676e2064a2a5ece8a0686573ea340aba559e75	2026-04-18 05:02:13-05	2026-03-19 05:02:24-05	2026-03-19 05:02:13-05
198	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.66b608c53f9709af98b4db5d9492c2eb19e2fff195c139fdab4110402abfa30a	2026-04-18 05:02:24-05	2026-03-19 05:02:25-05	2026-03-19 05:02:24-05
199	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.daff76bb95d9784449c7b4fb7b6fdb7d03838259de25ba9b7b56563c8f766290	2026-04-18 05:02:25-05	2026-03-19 05:02:36-05	2026-03-19 05:02:25-05
200	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.282d5e98f072d97820e4152bcf95725db82976a7ba3594d1c19c1114dfc17ba0	2026-04-18 05:02:36-05	2026-03-19 05:02:37-05	2026-03-19 05:02:36-05
201	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.f6e6e94afff767af233087b7e7edad7ac1ec038ed4194358216af1b633947928	2026-04-18 05:02:37-05	2026-03-19 05:02:48-05	2026-03-19 05:02:37-05
202	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.844ff14c6068c172d879f8f3aece2e68038b550d76eaa570779c8c7107c86f52	2026-04-18 05:02:48-05	2026-03-19 05:02:49-05	2026-03-19 05:02:48-05
203	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.c5d262335afc77bfe4c1cb59db72aec9c5737df53be1b4df56f6908a55549fca	2026-04-18 05:02:49-05	2026-03-19 05:03:00-05	2026-03-19 05:02:49-05
204	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.c125fb77b451e1dfde974d0dab0793306e9ba6d3f9b8cfeccebdcbc3ac20a980	2026-04-18 05:03:00-05	2026-03-19 05:03:01-05	2026-03-19 05:03:00-05
205	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.4fd84aaa341eca5a8d24a672cce22b9be94f2dde79ddfbbb72cd547dde1087fa	2026-04-18 05:03:01-05	2026-03-19 05:03:12-05	2026-03-19 05:03:01-05
206	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.e565abbd9e2a631e2bbe7767162a814d8b5ea07d73e44a44d808bd698c3eea21	2026-04-18 05:03:12-05	2026-03-19 05:03:13-05	2026-03-19 05:03:12-05
207	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.c138afad42bb2199c9a19d562d4268cee1eff4025eb1f4b5fa0401ee6a7b7bd3	2026-04-18 05:03:13-05	2026-03-19 05:03:24-05	2026-03-19 05:03:13-05
208	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.9e543ab316d17bf78f98a0ac15ddea38e610fb3cd4c65a227cec67b56839129b	2026-04-18 05:03:24-05	2026-03-19 05:03:25-05	2026-03-19 05:03:24-05
209	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.ba4bd3a1bde8c8b13f4f70ca3435a168d0197288a18339404a381970f41f4482	2026-04-18 05:03:25-05	2026-03-19 05:03:36-05	2026-03-19 05:03:25-05
210	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.d6284053d2165dce8cf1298e5608b20a7e875cc86322db153b397af83b356648	2026-04-18 05:03:36-05	2026-03-19 05:03:37-05	2026-03-19 05:03:36-05
211	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.6c7ebb0afde91237d58f5aafe53d1714741168133cc33747e3e803a8d8817c54	2026-04-18 05:03:37-05	2026-03-19 05:03:48-05	2026-03-19 05:03:37-05
212	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.1797508b5a8f05e4f50b140ff2b1847151cee5169474f062764f4144086f72cc	2026-04-18 05:03:48-05	2026-03-19 05:03:49-05	2026-03-19 05:03:48-05
213	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.0df7408b34383f92aeb00a0781c6de0e0f9c62ef8cb40c2baa0a0976000e26ff	2026-04-18 05:03:49-05	2026-03-19 05:04:00-05	2026-03-19 05:03:49-05
214	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.b59cf8c792edd552c7612a0f0307904d68b5832e9890348fedc76f0784f6597c	2026-04-18 05:04:00-05	2026-03-19 05:04:01-05	2026-03-19 05:04:00-05
215	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.42206ab78d51387b8886e3fb5d9eb22ac172ec2b9c68d1184e811053072cadbf	2026-04-18 05:04:01-05	2026-03-19 05:04:12-05	2026-03-19 05:04:01-05
216	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.88fb7a65743856fda20db1da2413e54705dbe39ff2f7ee0b278a7ad9100f358c	2026-04-18 05:04:12-05	2026-03-19 05:04:13-05	2026-03-19 05:04:12-05
217	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.4339de9864f55c57dd6cb0281dff6e1ff1327fe1e7307c996129f24b2dc645d0	2026-04-18 05:04:13-05	2026-03-19 05:04:24-05	2026-03-19 05:04:13-05
218	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.b39e15029b4fa4d4fe07d140f69d0d5bda2fb4df77193d387f1077026f118a2c	2026-04-18 05:04:24-05	2026-03-19 05:04:25-05	2026-03-19 05:04:24-05
219	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.15a414409d6af39a2923f182a1aea4459ca619f514576d67eaea002b10f66f6f	2026-04-18 05:04:25-05	2026-03-19 05:04:36-05	2026-03-19 05:04:25-05
220	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.1051903ed9a4946fbee2215ec1b5321351448ec5b36db5115b6a8c8b3b7a6b45	2026-04-18 05:04:36-05	2026-03-19 05:04:37-05	2026-03-19 05:04:36-05
221	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.d9e448d26a924b672756b18930b52046941d3ba43761d7296e3f2d71e8e69132	2026-04-18 05:04:37-05	2026-03-19 05:04:48-05	2026-03-19 05:04:37-05
222	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.ec1d78ea53efa6fbf356baa32217e281d5a141d438b28559f9074143c8d1fd2d	2026-04-18 05:04:48-05	2026-03-19 05:04:49-05	2026-03-19 05:04:48-05
223	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.17c5bde24c5aa2a2b058e458527d9fad79738dba65a50dbda0e7168619febf4c	2026-04-18 05:04:49-05	2026-03-19 05:05:00-05	2026-03-19 05:04:49-05
224	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.d0bb85d0969d3d34287b81d1fb0d324dc8bdb74649b1139aec0e4d8fcc1aba85	2026-04-18 05:05:00-05	2026-03-19 05:05:01-05	2026-03-19 05:05:00-05
225	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.0249e0cb9978a964dcf932f8ee9c7009488a9bd1c28ece5913f46b8c1412fa83	2026-04-18 05:05:01-05	2026-03-19 05:05:12-05	2026-03-19 05:05:01-05
226	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.3955b4c4eda696495361a107f2d19a46a3a23b4a25bf35a451b9515e2d938741	2026-04-18 05:05:12-05	2026-03-19 05:05:13-05	2026-03-19 05:05:12-05
227	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.ae580c5e8953905e69b822e24378d64c32fb1b29a18c0f0f647b1c61a5a96b19	2026-04-18 05:05:13-05	2026-03-19 05:05:24-05	2026-03-19 05:05:13-05
228	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.d97216ab6c06a4d00fb801b297a17897ad93a89954b2df5621e1ab59d60b4611	2026-04-18 05:05:24-05	2026-03-19 05:05:25-05	2026-03-19 05:05:24-05
229	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.2eed97e16e91f4e2e5efb497b878d46be50acf02adf0f538b6a015c08796ec85	2026-04-18 05:05:25-05	2026-03-19 05:05:36-05	2026-03-19 05:05:25-05
230	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.904295e15a32398703ff917fb9b89ad4e938b8d5be7b6fd0e4b31cbcb0f3578c	2026-04-18 05:05:36-05	2026-03-19 05:05:37-05	2026-03-19 05:05:36-05
231	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.e879f6a57ac2a655b3a21a4d5ee8390f220a8c5505925ad866ca3ec6cd9958f4	2026-04-18 05:05:37-05	2026-03-19 05:05:48-05	2026-03-19 05:05:37-05
232	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.3fd0d0097a43adca3f2d0a0542fa336372c8842ec0596bbc0df71bbd79777660	2026-04-18 05:05:48-05	2026-03-19 05:05:49-05	2026-03-19 05:05:48-05
233	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.dfdac7a941dd470623de508a13b64684608be2e4acc860330d95b98b031ea94c	2026-04-18 05:05:49-05	2026-03-19 05:06:00-05	2026-03-19 05:05:49-05
234	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.0d2aa7a89e6ffca55efa62fe4d5628c89e673389f53641822b349fb82ec600dc	2026-04-18 05:06:00-05	2026-03-19 05:06:01-05	2026-03-19 05:06:00-05
235	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.c9fa59256551d5d787d737fbef45f18ded7eafb8065bef612a96ec9a87034e38	2026-04-18 05:06:01-05	2026-03-19 05:06:12-05	2026-03-19 05:06:01-05
236	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.7825a62838ac2efbe97be28ce1c670f4d1d77dd81ec5c8fc83eba879930d64f8	2026-04-18 05:06:12-05	2026-03-19 05:06:13-05	2026-03-19 05:06:12-05
237	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.4a1b9ded31947d011d752e04ce4cfd70f32d50c5ad0548a1308ce32dac3efa6c	2026-04-18 05:06:13-05	2026-03-19 05:06:24-05	2026-03-19 05:06:13-05
238	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.527f8b8285be4e0f9d75dae563bd807a5fd6a95f05d89c508e372e74836f7625	2026-04-18 05:06:24-05	2026-03-19 05:06:25-05	2026-03-19 05:06:24-05
239	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.40e3fed2bb6454f0d0748bb3d1766ba94ebfd3f72ac84bef6b949790a84d6dd3	2026-04-18 05:06:25-05	2026-03-19 05:06:36-05	2026-03-19 05:06:25-05
240	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.12cc64cf8f9456035cbfd89475ef2ec005b6734a87d4ef413fe94286a2d110fb	2026-04-18 05:06:36-05	2026-03-19 05:06:37-05	2026-03-19 05:06:36-05
241	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.327b61d79f03be362418ad9ff9a369c6d0153730748d62b0a19e42749bc7d806	2026-04-18 05:06:37-05	2026-03-19 05:06:48-05	2026-03-19 05:06:37-05
242	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.bebcbf89418bcd0de7a89ccebe040561fc382b1d4b657525f58bbce5b266f651	2026-04-18 05:06:48-05	2026-03-19 05:06:49-05	2026-03-19 05:06:48-05
243	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.157522d01d5a2de61123f71e5a4b226131f60d063d2bd057be0eda42660c123b	2026-04-18 05:06:49-05	2026-03-19 05:07:00-05	2026-03-19 05:06:49-05
244	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.223cc893de78ea62026211651af9782054aab5760cffa33e60487570aabc4245	2026-04-18 05:07:00-05	2026-03-19 05:07:01-05	2026-03-19 05:07:00-05
245	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.f39322943f6d50e8fa083deadaee4f9d9d3ce74f41e4bd7f83f68d9a7bf48e23	2026-04-18 05:07:01-05	2026-03-19 05:07:12-05	2026-03-19 05:07:01-05
246	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.591be23292ca069de19d3992baf0532c67196e13d37478c2062deb661ff4a5ad	2026-04-18 05:07:12-05	2026-03-19 05:07:13-05	2026-03-19 05:07:12-05
247	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.4655be0403149a32d1f4f750851aebf8f377b5df921370739bdef68b070b3ebf	2026-04-18 05:07:13-05	2026-03-19 05:07:24-05	2026-03-19 05:07:13-05
248	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.e0a470859c3bad9c7be4be097ca06ff9e86777db1e211504b8314bf53b80cea4	2026-04-18 05:07:24-05	2026-03-19 05:07:25-05	2026-03-19 05:07:24-05
249	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.be8ab5c57716f5d2bfd699f913fef47da1734d71b76f842969bed9cdc600bae0	2026-04-18 05:07:25-05	2026-03-19 05:07:36-05	2026-03-19 05:07:25-05
250	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.c404eb3da847dd6066c3d20e951470739a79f0dc2529193382e768ca1e0ff066	2026-04-18 05:07:36-05	2026-03-19 05:07:37-05	2026-03-19 05:07:36-05
251	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.41de633bb8ee112743c63c595fbc26604a2ee94841d269acbca93f7cffbc57e6	2026-04-18 05:07:37-05	2026-03-19 05:07:48-05	2026-03-19 05:07:37-05
252	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.98ec4b88031dbce9a0a5f091dcb8382a8bf1dcdfb85e5bd29eed8a61f7d23b7e	2026-04-18 05:07:48-05	2026-03-19 05:07:49-05	2026-03-19 05:07:48-05
253	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.7061e783085e11d7121c4a88d91206744b4ed0090173706c982f888a32c28e2a	2026-04-18 05:07:49-05	2026-03-19 05:08:00-05	2026-03-19 05:07:49-05
254	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.bc478401f4993d787f6e71e8c583055609279925afc351cd08cb9455ca31d708	2026-04-18 05:08:00-05	2026-03-19 05:08:01-05	2026-03-19 05:08:00-05
255	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.b5d7c78b71351e9f8ee6dc5a4088c426e4a33b9253b7df992e4d0e081780d795	2026-04-18 05:08:01-05	2026-03-19 05:08:12-05	2026-03-19 05:08:01-05
256	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.bb383444cf12239c1ed198a664635177bfeb49dd199e27d8d9c5d0438cfaf320	2026-04-18 05:08:12-05	2026-03-19 05:08:13-05	2026-03-19 05:08:12-05
257	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.f3456dbddc5a8f6bada2551d9df3bdbd535110925eec9f8aedfc767cd7cded21	2026-04-18 05:08:13-05	2026-03-19 05:08:24-05	2026-03-19 05:08:13-05
258	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.8bfb6a9af67eb59a82f44625620ec4b618b9f3bc15c69d723467ffa3f4c68c4f	2026-04-18 05:08:24-05	2026-03-19 05:08:25-05	2026-03-19 05:08:24-05
259	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.757a7e551ae64ac6667f7fecdcdc0a0dfd21a5477bc9edd661d8c68642f8c859	2026-04-18 05:08:25-05	2026-03-19 05:08:36-05	2026-03-19 05:08:25-05
260	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.ef1247dc8191d04bbf4cca3b6d94eade2de12bae2b31a4b28eb03493df694045	2026-04-18 05:08:36-05	2026-03-19 05:08:37-05	2026-03-19 05:08:36-05
261	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.444e74c34b66bfa47deb396a71f3bb169df2dcac159895ad7ecbb38672c7e8c5	2026-04-18 05:08:37-05	2026-03-19 05:08:48-05	2026-03-19 05:08:37-05
262	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.2188ffd453725e087b7ebcf64f2184c0ced7e4be31712c9b456f95d1a7253ce3	2026-04-18 05:08:48-05	2026-03-19 05:08:49-05	2026-03-19 05:08:48-05
263	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.cd08366405bb53c8704cd58910bb52c3d2a497aca8f64909f98d04a119c472df	2026-04-18 05:08:49-05	2026-03-19 05:09:00-05	2026-03-19 05:08:49-05
264	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.8b238a4374849f4f22c13fda972e8efd4d6e53f05755e265ef0568dd07862fb0	2026-04-18 05:09:00-05	2026-03-19 05:09:01-05	2026-03-19 05:09:00-05
265	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.9ca737aa0b6d508397dd45384701aeeb78e4058b3fa6ee9adef047e36ba17ed7	2026-04-18 05:09:01-05	2026-03-19 05:09:12-05	2026-03-19 05:09:01-05
266	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.36ac50464e1397f886598eb94b7515a0e06e4db75dfffab559fd40c3f937c56e	2026-04-18 05:09:12-05	2026-03-19 05:09:13-05	2026-03-19 05:09:12-05
267	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.d7ada8ef65b5e9bb2056e4d1f3ca3705dcc15c48fc38949a1251c0dd1e54f922	2026-04-18 05:09:13-05	2026-03-19 05:09:24-05	2026-03-19 05:09:13-05
268	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.83ff3cb2856bae2e2af5f350f9983e25e45e4a80e8dae067c88b707be04fd916	2026-04-18 05:09:24-05	2026-03-19 05:09:25-05	2026-03-19 05:09:24-05
269	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.939c30175014120877b2b25b795c65ac9cecaad11cf6cb1fdcdf1d85172f4f59	2026-04-18 05:09:25-05	2026-03-19 05:09:36-05	2026-03-19 05:09:25-05
270	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.329a403a153a16082648c29df08408ed97577e54868c1b36ba75ae2115367851	2026-04-18 05:09:36-05	2026-03-19 05:09:37-05	2026-03-19 05:09:36-05
271	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.5ced13b110073e76ac0466d80582f18eec2fca91eefecd73385ac59e3901b11f	2026-04-18 05:09:37-05	2026-03-19 05:09:48-05	2026-03-19 05:09:37-05
272	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.afd9fda12a25bc4fcda2eccd06ee6ea599275a5fa102d8f09c96908674cdc59b	2026-04-18 05:09:48-05	2026-03-19 05:09:49-05	2026-03-19 05:09:48-05
273	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.5760ceba9e4b3c3dda6cb249d8094e787c59dbad891b76eecf3dab48cbad0f98	2026-04-18 05:09:49-05	2026-03-19 05:10:00-05	2026-03-19 05:09:49-05
274	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.c341feb3c1429ccd42201e83c30dfbcbe8002886f4d4c48121542346a34bc135	2026-04-18 05:10:00-05	2026-03-19 05:10:01-05	2026-03-19 05:10:00-05
275	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.0d16763c82938ca5460a75070f34c53954c33343bd6b9d0ca620c03e5b5ae89a	2026-04-18 05:10:01-05	2026-03-19 05:10:12-05	2026-03-19 05:10:01-05
276	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.ab75a32ee26be318a5fa875c913bbceaea9931673fed92b103ecaeaefb219650	2026-04-18 05:10:12-05	2026-03-19 05:10:13-05	2026-03-19 05:10:12-05
277	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.a990dba05eedef421b16ea68e8db375d88e804aba8cdf29595a28763d8a742ea	2026-04-18 05:10:13-05	2026-03-19 05:10:24-05	2026-03-19 05:10:13-05
278	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.41c8163e087da23503462f8bdbe90cd213d96b339f404ca0db6299d7810778b7	2026-04-18 05:10:24-05	2026-03-19 05:10:25-05	2026-03-19 05:10:24-05
279	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.1e193638f499307948796c4e6357771ebfc25820c82fafdbc3d5ef4157d1946e	2026-04-18 05:10:25-05	2026-03-19 05:10:36-05	2026-03-19 05:10:25-05
280	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.c46cede227174f4b9ff6be7a2a16e9a0dc6ee9acbf618ecd29596362b0ac3312	2026-04-18 05:10:36-05	2026-03-19 05:10:37-05	2026-03-19 05:10:36-05
281	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.57727b4462362c42d2fb94abe6ac4e0e03d3a74cb8218b45b29d5e7c742f8c9d	2026-04-18 05:10:37-05	2026-03-19 05:10:48-05	2026-03-19 05:10:37-05
282	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.09759b0d12b848efa6ce0a270ede3caf161b1232ee2d2f8a307a79fb991d524a	2026-04-18 05:10:48-05	2026-03-19 05:10:49-05	2026-03-19 05:10:48-05
283	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.caa5225bca4ad24cda39bded5a0fd8a3c7419593f01a8b403aa1567667289ee4	2026-04-18 05:10:49-05	2026-03-19 05:11:00-05	2026-03-19 05:10:49-05
284	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.d62e3ea114d6e31478a9bfa71d44ff206cc8390593a0f079a05d0b04b0b7f98e	2026-04-18 05:11:00-05	2026-03-19 05:11:01-05	2026-03-19 05:11:00-05
285	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.93c518caf07c380a375ab9d27b2ae649777971a55ff9e560d026e3f0b46ebbb0	2026-04-18 05:11:01-05	2026-03-19 05:11:12-05	2026-03-19 05:11:01-05
286	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.3abd1325d0c1f548737f6046d1fba58257421f8f4f7fe0aac15d213c8bf00273	2026-04-18 05:11:12-05	2026-03-19 05:11:13-05	2026-03-19 05:11:12-05
287	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.92ef81c41f078f9087fca5e47cbaae53a04a5d1d1b32ae3f32b7383b1c82b28a	2026-04-18 05:11:13-05	2026-03-19 05:11:24-05	2026-03-19 05:11:13-05
288	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.9e1a6413d7c938b60c50efd512cc4a220913669cf5ea33c16b70ce3b75834a10	2026-04-18 05:11:24-05	2026-03-19 05:11:25-05	2026-03-19 05:11:24-05
289	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.af9ae77f0aa78400ac346b95d4355063a18ad6f644c9cd4ef015ab95d68f7979	2026-04-18 05:11:25-05	2026-03-19 05:11:36-05	2026-03-19 05:11:25-05
290	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.e0b4acab084e9facd4996f8132540b1aa82a5fc3185c5bb920ba2ba54ae94446	2026-04-18 05:11:36-05	2026-03-19 05:11:37-05	2026-03-19 05:11:36-05
291	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.3dfda2834581156ed3470bbad30718f1faaa1519376d0230a4989b4e8c26f6b7	2026-04-18 05:11:37-05	2026-03-19 05:11:48-05	2026-03-19 05:11:37-05
292	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.bf1d4b84dc420c917b1ce8e4b1c94f8d07589d39d5842372ccf4fc27275a621c	2026-04-18 05:11:48-05	2026-03-19 05:11:49-05	2026-03-19 05:11:48-05
293	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.8d509d8f06959f4d8fa0806b1cfa639c96c115329bd658ddbbda939464042c73	2026-04-18 05:11:49-05	2026-03-19 05:12:00-05	2026-03-19 05:11:49-05
294	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.39176ed626ca23d1591eccd6c79b3bb34957a8878accdae3231edaa9615a4369	2026-04-18 05:12:00-05	2026-03-19 05:12:01-05	2026-03-19 05:12:00-05
295	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.c1e251d84be2114ba9e33b58b630cee2b5e29e6409706f76ef9a7ab3808d3776	2026-04-18 05:12:01-05	2026-03-19 05:12:12-05	2026-03-19 05:12:01-05
296	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.145516a3b540c712bd3f2b5037eb26ff7f88323c9359a375c3b456a34e5898f2	2026-04-18 05:12:12-05	2026-03-19 05:12:13-05	2026-03-19 05:12:12-05
297	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.da95783aa67c8739d992758298e33933dd722a4ef78ebd65aa783ed4eac23027	2026-04-18 05:12:13-05	2026-03-19 05:12:24-05	2026-03-19 05:12:13-05
298	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.64b3b4a22cf72d77b7eacceaedd0d1ce370994dab88f8f14f517d490877a02a3	2026-04-18 05:12:24-05	2026-03-19 05:12:25-05	2026-03-19 05:12:24-05
299	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.5919774ebd4993f146926674456f0d743883771452998ad3397589107ebd0f2a	2026-04-18 05:12:25-05	2026-03-19 05:12:36-05	2026-03-19 05:12:25-05
300	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.c34ba0a3b686994a48eddb98e63342cbe7a87d76d775405ff35e5ed6ab340c52	2026-04-18 05:12:36-05	2026-03-19 05:12:37-05	2026-03-19 05:12:36-05
301	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.a48515a8d0c7737729556f8f719625e5b155d30ef22ed2ac37e2b9b0e4165b7a	2026-04-18 05:12:37-05	2026-03-19 05:12:48-05	2026-03-19 05:12:37-05
302	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.084b264ed672e8c3f704c05b67334a533add6b6e094e2d06e1f5db504a3ef3d2	2026-04-18 05:12:48-05	2026-03-19 05:12:49-05	2026-03-19 05:12:48-05
303	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.e9b244444b8615bbfe75db8a0c45e5d55b5808df0019c5d2c5e794ba32f477cc	2026-04-18 05:12:49-05	2026-03-19 05:13:00-05	2026-03-19 05:12:49-05
304	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.cb2c93b478f5e96e0a79a3054a3bfa4afa387355c9d8c82b91ee024ffeea6108	2026-04-18 05:13:00-05	2026-03-19 05:13:01-05	2026-03-19 05:13:00-05
305	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.a9fb25717dffa09826dd729d885e7ed1a9abf2bb290a3ae9be787192cc82ddc4	2026-04-18 05:13:01-05	2026-03-19 05:13:12-05	2026-03-19 05:13:01-05
306	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.eec0820da99c3795a42f19e1083f0113947abd7993cba645e8c06961f85ae035	2026-04-18 05:13:12-05	2026-03-19 05:13:13-05	2026-03-19 05:13:12-05
307	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.ad368538c46969ed1935c46fb1c9efd6bb2ba7fd7fb07605f93ae1b88d8a2b7c	2026-04-18 05:13:13-05	2026-03-19 05:13:24-05	2026-03-19 05:13:13-05
308	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.7a51d3e38664b8343b30a3b8e7326f179fb4fba87014b1ac8e262693bfa1672e	2026-04-18 05:13:24-05	2026-03-19 05:13:25-05	2026-03-19 05:13:24-05
309	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.d57ccacbcdbf22a0db75f3b8bd362272e6ec2ab0abeec058e977955a1dc27f0a	2026-04-18 05:13:25-05	2026-03-19 05:13:36-05	2026-03-19 05:13:25-05
310	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.f0e480531a3a8fe5e61e18ab9950c073f39b6644421321d9ef1598978f15de4f	2026-04-18 05:13:36-05	2026-03-19 05:13:37-05	2026-03-19 05:13:36-05
311	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.b6af54c445f632a709af2c570fc0657f7c98b65fc95ad7b3f31a4e899ee7141b	2026-04-18 05:13:37-05	2026-03-19 05:13:48-05	2026-03-19 05:13:37-05
312	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.52179e9b410e120eaf19f4c11ca2acf9447775fe604889bf637d2e90d1b031ea	2026-04-18 05:13:48-05	2026-03-19 05:13:49-05	2026-03-19 05:13:48-05
313	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.a4f499d4d42e65002d2cd76bec5a1c09719d6a5cdeb5d8e5d82398272276ff8f	2026-04-18 05:13:49-05	2026-03-19 05:14:00-05	2026-03-19 05:13:49-05
314	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.7ac95c702b2c9a0a71fe4ca754a6889bdff485f025662997d5820dd80a0fe30c	2026-04-18 05:14:00-05	2026-03-19 05:14:01-05	2026-03-19 05:14:00-05
315	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.91d36813853f4f3b080dda85d7dd6a1d39a92ad711e9af3947e7367f5a1d5ce6	2026-04-18 05:14:01-05	2026-03-19 05:14:12-05	2026-03-19 05:14:01-05
316	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.e3a24bfd419a9b8651f2af32a6332f553859ee3dcae91baac5ab3590a0bc3dd2	2026-04-18 05:14:12-05	2026-03-19 05:14:13-05	2026-03-19 05:14:12-05
317	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.0671c81d51d40ad7d2c5526adda18fe982e02c50e38a550ed4ec3143025eae8e	2026-04-18 05:14:13-05	2026-03-19 05:14:24-05	2026-03-19 05:14:13-05
318	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.e0cf0a264add3efdd7137f740f68388401359024f4ecc1af99e07d85579fe1ab	2026-04-18 05:14:24-05	2026-03-19 05:14:25-05	2026-03-19 05:14:24-05
319	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.1bab1e9817b89341805482c0b826555eb961a1dafbf65ab5c2c0e9e2f8595750	2026-04-18 05:14:25-05	2026-03-19 05:14:36-05	2026-03-19 05:14:25-05
320	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.99eae4bff8cb106d8226d7035ea232bb5dc94920fdc01346754e1aa7a20e0818	2026-04-18 05:14:36-05	2026-03-19 05:14:37-05	2026-03-19 05:14:36-05
321	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.f1be90d2bee59256e3cc28f31f79694acbbb720e919a1b4141c053e346b511b7	2026-04-18 05:14:37-05	2026-03-19 05:14:48-05	2026-03-19 05:14:37-05
322	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.512368852bca6abc69d63aa536f0fac441ca63fa7c2b0c345bc1101107cb0c4e	2026-04-18 05:14:48-05	2026-03-19 05:14:49-05	2026-03-19 05:14:48-05
323	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.fd0af76886d09d605e9a04975b52694cee56c29761a8b7a9b1a353b4e3aed640	2026-04-18 05:14:49-05	2026-03-19 05:15:00-05	2026-03-19 05:14:49-05
324	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.75e7ff2f10a2b6e9e9bda3ac5868dffa4ccb74550d29fe0f3202f90abf98391b	2026-04-18 05:15:00-05	2026-03-19 05:15:01-05	2026-03-19 05:15:00-05
325	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.efe21be7dcef79e49a026e8db021a1444ca216ed105f02531505f96ed7fb5738	2026-04-18 05:15:01-05	2026-03-19 05:15:12-05	2026-03-19 05:15:01-05
326	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.b234c27a26a396742fe054cfe81351f3903c133b07f0ca13b19173c138868263	2026-04-18 05:15:12-05	2026-03-19 05:15:13-05	2026-03-19 05:15:12-05
327	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.ce068293b14fb693f7d3c1c76e066b07a9f6f34f7ea67f5ed1f40644f902872e	2026-04-18 05:15:13-05	2026-03-19 05:15:24-05	2026-03-19 05:15:13-05
328	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.9b82fd82184a5796d33d796555d382306391a2948e3da2e263eba04d6b4a3921	2026-04-18 05:15:24-05	2026-03-19 05:15:25-05	2026-03-19 05:15:24-05
329	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.8d80ef42ad5ba208f62466959ff7891f64a34be0d3122ead64319c7ce7c2495c	2026-04-18 05:15:25-05	2026-03-19 05:15:36-05	2026-03-19 05:15:25-05
330	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.083a6a860908e9aaf8b895f6db59739e628d826dae1d0e613f9aeb5ee4290497	2026-04-18 05:15:36-05	2026-03-19 05:15:37-05	2026-03-19 05:15:36-05
331	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.f7e2f4035934f4e7ad4ffcbdc549a87847e5df6b7351b4c90053abee78b72f4d	2026-04-18 05:15:37-05	2026-03-19 05:15:48-05	2026-03-19 05:15:37-05
332	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.5e6dfd00cfc3da0f3f1ef3528dc2bce44803d2bffcb34879d38c522b03242f88	2026-04-18 05:15:48-05	2026-03-19 05:15:49-05	2026-03-19 05:15:48-05
333	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.3a3272167e8b2425ebb293759122e09410011cc182841ad224692c01797966e6	2026-04-18 05:15:49-05	2026-03-19 05:16:00-05	2026-03-19 05:15:49-05
334	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.9df866e005d8fbf9cb583a98e7507b14c722ec5467c5f1274e99803a9841fd5c	2026-04-18 05:16:00-05	2026-03-19 05:16:01-05	2026-03-19 05:16:00-05
335	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.313036a0f0e460ee28f9339a4592f23540f3bf42d16aa4413dde950bc3bb3f53	2026-04-18 05:16:01-05	2026-03-19 05:16:02-05	2026-03-19 05:16:01-05
336	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.bd71e82d66830c758d2c18eedc6f61dee00f7086c8290592fd97b882995c602b	2026-04-18 05:16:02-05	2026-03-19 05:16:04-05	2026-03-19 05:16:02-05
337	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.5a6c3ad7ec3bd59cec009113628f1597efeef6edecae51a8738ed18ce5f099c7	2026-04-18 05:16:04-05	2026-03-19 05:16:04-05	2026-03-19 05:16:04-05
338	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.31dde04d6fa33a59ccbee79f44d6667364f8cb1293b800ed895e9aeb0ce9d7df	2026-04-18 05:16:04-05	2026-03-19 05:16:05-05	2026-03-19 05:16:04-05
339	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.b954e84970f82d26cfd13230ba2c9cb3e43ab798986e3d6311ca4b0864a3ca81	2026-04-18 05:16:05-05	2026-03-19 05:16:06-05	2026-03-19 05:16:05-05
340	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.27e5e7257c917c72daf9e1d31fa430504f42cdbb1ac5e29a34c2e561cbf0bbb5	2026-04-18 05:16:06-05	2026-03-19 05:16:07-05	2026-03-19 05:16:06-05
341	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.1750759f91dc15b29bb15460f702b659ba4510d0390db07140e67356575d16fc	2026-04-18 05:16:07-05	2026-03-19 05:16:08-05	2026-03-19 05:16:07-05
342	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.b4fea7e32cd8060602fa0a5f839130274c1e90930b5405caac98544aa74d18b1	2026-04-18 05:16:08-05	2026-03-19 05:16:09-05	2026-03-19 05:16:08-05
343	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.d8684f3b7b82c7e331f3535201eb5a70305a16a83a37537fd5da04e048171945	2026-04-18 05:16:09-05	2026-03-19 05:16:10-05	2026-03-19 05:16:09-05
344	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.f4c8bb9942bcd3aa1e3e3eb17203cc1ee7ba20479230602daf74faf5a3980457	2026-04-18 05:16:10-05	2026-03-19 05:16:11-05	2026-03-19 05:16:10-05
345	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.3b9f10f968c419e94ea326eb44d7e393e2b50aeb479ab442bb6c5b67770d23d5	2026-04-18 05:16:11-05	2026-03-19 05:16:12-05	2026-03-19 05:16:11-05
346	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.5870c5f70afafd1c6df3501362b79678e22528c92cbf0289e174d3b4acdfc64f	2026-04-18 05:16:12-05	2026-03-19 05:16:13-05	2026-03-19 05:16:12-05
347	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.e878807577db14fb429444f1ca6f81bd02ec6a22de8b6c4376af41aecf5ad8e7	2026-04-18 05:16:13-05	2026-03-19 05:16:14-05	2026-03-19 05:16:13-05
348	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.20f4102e334692d100e6ceb4a022926eeac6af91206e194d17e168be1e37ddc9	2026-04-18 05:16:14-05	2026-03-19 05:16:15-05	2026-03-19 05:16:14-05
349	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.e0948974faafdf693e98ce2157fccf1f188b7418b7667b5c316ef8ec95029051	2026-04-18 05:16:15-05	2026-03-19 05:16:15-05	2026-03-19 05:16:15-05
350	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.c8b90aad6889b92e23bd677ede422a72dc5f536e8de1282dfefa98db795c6b22	2026-04-18 05:16:15-05	2026-03-19 05:16:16-05	2026-03-19 05:16:15-05
351	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.1106d0941b87914257ae29ee9fd932d05f81360b35c62a3baf1320975771b5a0	2026-04-18 05:16:16-05	2026-03-19 05:16:17-05	2026-03-19 05:16:16-05
352	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.c64239b7f169eb591d0b6091a99720efd289aa9b595db17f56d0b300b85ce202	2026-04-18 05:16:17-05	2026-03-19 05:16:19-05	2026-03-19 05:16:17-05
353	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.9e6505d63255817a2f3772c179244440ae1ce37fa88e225ba46949645fab6d50	2026-04-18 05:16:19-05	2026-03-19 05:16:20-05	2026-03-19 05:16:19-05
354	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.54928ce3dfee1bace1499d315ec274013c47b04e2508e1f89a978c1c2bbc445f	2026-04-18 05:16:20-05	2026-03-19 05:16:21-05	2026-03-19 05:16:20-05
355	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.d0b31ecdbb8c9e1c9ed9f7408f93f932836fba2137e68187437051980495380c	2026-04-18 05:16:21-05	2026-03-19 05:16:22-05	2026-03-19 05:16:21-05
356	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.66e3406867223f4b1f4d15a44d897794a93f9294541f7fdc166c6abfb7a21a20	2026-04-18 05:16:22-05	2026-03-19 05:16:23-05	2026-03-19 05:16:22-05
357	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.ea2ea8b5c7ed17a798f95ee2490d538005cd044e50797339d8f1a9ead8d89817	2026-04-18 05:16:23-05	2026-03-19 05:16:24-05	2026-03-19 05:16:23-05
358	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.4d7269523e1ebb0b70640396f0d3677af2a2b82ee8e38d4d15dc05c491490b1b	2026-04-18 05:16:24-05	2026-03-19 05:16:25-05	2026-03-19 05:16:24-05
359	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.da8313b7c021c29da0b4d4134b5368437d17e06849108f2677ac2a1bac0673cd	2026-04-18 05:16:25-05	2026-03-19 05:16:26-05	2026-03-19 05:16:25-05
360	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.210d006aa2f18ab1a2ead9d8a26c40d38b3a0d43d45820953e64cbe055f0857d	2026-04-18 05:16:26-05	2026-03-19 05:16:27-05	2026-03-19 05:16:26-05
361	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.900894659aecc74b54b772efe86a72421540bc3c80f6251d7836c8864aaa31bb	2026-04-18 05:16:27-05	2026-03-19 05:16:28-05	2026-03-19 05:16:27-05
362	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.c155ca556441d5e37857f6acd05c5ec977550af1dd8b2dfa45801a66a7452029	2026-04-18 05:16:28-05	2026-03-19 05:16:30-05	2026-03-19 05:16:28-05
363	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.d177a9e009aef80313b83e724b8cf6accb797d448461c8087e4d4ed16ef67ef5	2026-04-18 05:16:30-05	2026-03-19 05:16:31-05	2026-03-19 05:16:30-05
364	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.48b536d2a6527dd3d17dedc267a6525d95fed877b3cb4bbebbe2868c72859f1b	2026-04-18 05:16:31-05	2026-03-19 05:16:32-05	2026-03-19 05:16:31-05
365	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.350f3c34e819c395cfd5f96b645bd45309c504e0877ca7e0bc40e34863c50bdf	2026-04-18 05:16:32-05	2026-03-19 05:16:33-05	2026-03-19 05:16:32-05
366	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.b2b41d63593472e33da537fcd11ab98c9a9b6b3a4bb3fa89bcdae217b1e8ad64	2026-04-18 05:16:33-05	2026-03-19 15:59:58-05	2026-03-19 05:16:33-05
367	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.22a8af89dfba64ae5de8f3ced2efb4c66fe8d16916a0286d7caf09960b04c7c9	2026-04-18 15:59:58-05	2026-03-19 16:41:14-05	2026-03-19 15:59:58-05
368	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.a21ab61daa1f5f2aee952b61a6322f2639af272f3c4c09d88a95942a395905af	2026-04-18 16:41:14-05	2026-03-19 16:41:15-05	2026-03-19 16:41:14-05
369	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.fdb96ee48c265b1ff107798baff364d1f6272f23982a4657c1dd3764e80eda97	2026-04-18 16:41:15-05	2026-03-19 16:41:17-05	2026-03-19 16:41:15-05
370	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.3de6630ae3f31e39a0985ad7621d561d166521b5988009c98f1a98e44e120eb5	2026-04-18 16:41:17-05	2026-03-19 16:41:18-05	2026-03-19 16:41:17-05
371	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.ef03a3dea962b67abf0bb6529ffc3f7faeade5e6e63aaf3a5729b691e9a2541d	2026-04-18 16:41:18-05	2026-03-19 16:41:19-05	2026-03-19 16:41:18-05
372	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.688ec51276bcea72497aaad1b947982daec3a6309d793c1ff287162a92d25935	2026-04-18 16:41:19-05	2026-03-19 16:41:20-05	2026-03-19 16:41:19-05
373	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.9e530a86d7bd6199fe4d4862f29dc213096723f22b4e4ccd7734af5543b04f1e	2026-04-18 16:41:20-05	2026-03-19 16:41:22-05	2026-03-19 16:41:20-05
374	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.7d3b69848ceb9e5926adf8de1c70f152bb6a744b74f448d2b2900f11071b2312	2026-04-18 16:41:22-05	2026-03-19 16:41:23-05	2026-03-19 16:41:22-05
375	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.d9222933045ae0bd04b4b576508268a8159952b9aeed19f10ae4c2c31c8dbb76	2026-04-18 16:41:23-05	2026-03-19 16:41:25-05	2026-03-19 16:41:23-05
376	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.ab639d4843d89b2584dc56016e62412e1ee9ab8bafdb1bc28919898bb5507186	2026-04-18 16:41:25-05	2026-03-19 16:41:25-05	2026-03-19 16:41:25-05
377	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.acf4ff92900c129245e79b1e3ae3a7f25425b0defe9873c0ae15ab0ecc1bda4d	2026-04-18 16:41:25-05	2026-03-19 16:41:27-05	2026-03-19 16:41:25-05
378	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.1010b14e9dc042aa7753ef042f54dde8150424e74a873171fb8c6fde1eaa7f9c	2026-04-18 16:41:27-05	2026-03-19 16:41:27-05	2026-03-19 16:41:27-05
379	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.bf7d816f96c5b022ec5630a3c2d751c2101a6a2d9e83c947937dac5373f34bce	2026-04-18 16:41:27-05	2026-03-19 16:41:29-05	2026-03-19 16:41:27-05
380	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.9b75f2f2c17c2caaede1c92c31f0baa3ff2e19e40d399644fbe1e135821413bc	2026-04-18 16:41:29-05	2026-03-19 16:41:29-05	2026-03-19 16:41:29-05
381	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.b75e1f927bacd730d3159c56523f9c69838d5318d17b244e1fd1c132d8eee055	2026-04-18 16:41:29-05	2026-03-19 16:41:31-05	2026-03-19 16:41:29-05
382	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.b0480b8169013a73ff9c27cba597ea929adfe980d4b222f7416b46c8a2f43b5c	2026-04-18 16:41:31-05	2026-03-19 16:41:32-05	2026-03-19 16:41:31-05
383	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.612865b5c082e3a373d5e3a627efd8467686b8a25ea50a1da53ef0aeb10eb98b	2026-04-18 16:41:32-05	2026-03-19 16:41:33-05	2026-03-19 16:41:32-05
384	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.f46631e5d31508b6501c3c1df9c3096ae83e798de204773cbacdde9c7b8a0eaf	2026-04-18 16:41:33-05	2026-03-19 16:41:34-05	2026-03-19 16:41:33-05
385	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.9ec8a181e8cc31d6c06e068dd54575934354d05b2dccf2f81265d57a3b6ed572	2026-04-18 16:41:34-05	2026-03-19 16:43:25-05	2026-03-19 16:41:34-05
386	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.b5936d70f7e299ee8ac24e681cdc09157e41599dd4cc1d865b914819b1140818	2026-04-18 16:43:25-05	2026-03-19 20:08:22-05	2026-03-19 16:43:25-05
387	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.10f8e34c0a716e967fc7a2933d085da25b261ab0533cef4169f6a462f3bd3f02	2026-04-18 20:08:22-05	2026-03-19 20:55:07-05	2026-03-19 20:08:22-05
388	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.facfcad09e0acc440a200aaa2fa96699e815b18b0790c52c8ee9c8a89e260a34	2026-04-18 20:55:07-05	2026-03-19 21:25:10-05	2026-03-19 20:55:07-05
389	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.26d4c023cfe8c9293741694df7657702a2ecb90598c0436bbc14a22421ded257	2026-04-18 21:25:10-05	2026-03-19 21:25:12-05	2026-03-19 21:25:10-05
390	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.820ff4321165a9bbda1560618a734968592390ccb9a15c6142c870f20a768da1	2026-04-18 21:25:12-05	2026-03-19 21:25:13-05	2026-03-19 21:25:12-05
391	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.158b95a42ee0b68b2a28a69d2c16ce9100551353cf73356f7b3e856c2296bf0c	2026-04-18 21:25:13-05	2026-03-19 21:25:14-05	2026-03-19 21:25:13-05
392	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.a36bc391c7408d15e99a6bcdc8a194c8da5bfdaf0075ba3725f10d51313d3590	2026-04-18 21:25:14-05	2026-03-19 21:25:15-05	2026-03-19 21:25:14-05
393	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.f1c6b1ee43d4558a605f4c540ef127a2cd59e50092a6029072b176e683f72b43	2026-04-18 21:25:15-05	2026-03-19 21:25:16-05	2026-03-19 21:25:15-05
394	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.fefea5cedc6f87a24457ef7409cd4a8b62692898228b98da456df8c486b8c877	2026-04-18 21:25:16-05	2026-03-19 21:25:18-05	2026-03-19 21:25:16-05
395	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.1d186d90a9cddd329d0ac7d4d89663b1be81fc24331cd0e1d8c9a0b3474d5a75	2026-04-18 21:25:18-05	2026-03-19 21:25:19-05	2026-03-19 21:25:18-05
396	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.4562c815266de34ed4899b57f3776ffc8ccab4ce2e801804fafb2581191e000d	2026-04-18 21:25:19-05	2026-03-19 21:25:20-05	2026-03-19 21:25:19-05
397	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.6671b10eb68273cf9a43f603a6bc1035b7e15af795479be72ea7ce2dfdfcf11f	2026-04-18 21:25:20-05	2026-03-19 21:25:21-05	2026-03-19 21:25:20-05
398	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.00a3e9616c181f3788669433222ab7699fd70dab765f30c7279f598820d9cfb0	2026-04-18 21:25:21-05	2026-03-19 21:25:22-05	2026-03-19 21:25:21-05
399	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.11a0f5406144270b6e3dd77ccc46d8016dee88122a829448570c3af895049bb0	2026-04-18 21:25:22-05	2026-03-19 21:25:23-05	2026-03-19 21:25:22-05
400	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.9fdcd3058c10f19cb240d7002d852dfe441a3146a26fe385a6ed21dc2889bd52	2026-04-18 21:25:23-05	2026-03-19 21:25:24-05	2026-03-19 21:25:23-05
401	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.1194112db1c2a57043dd77c3a02a73c29c4d083a9a875fdae32ed72c24cad0ac	2026-04-18 21:25:24-05	2026-03-19 21:25:26-05	2026-03-19 21:25:24-05
402	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.ed9fe74157ece05c9d18acd72085f0f26a83876c0689141c4f33aef2f9cd2fbe	2026-04-18 21:25:26-05	2026-03-19 21:25:27-05	2026-03-19 21:25:26-05
403	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.dfbaa8c0c0507b5863f145f93287652d83e93278fa8aa068cf6596822323bd6a	2026-04-18 21:25:27-05	2026-03-19 21:25:28-05	2026-03-19 21:25:27-05
404	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.748f7d686df717b43d42a26b8ddedde23faa789059b0eaa0a9fc89e21e82f051	2026-04-18 21:25:28-05	2026-03-19 21:25:29-05	2026-03-19 21:25:28-05
405	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.c85a79187d6bb7be68714e908e18c1619b0548888a3935575cc7eebf3117971a	2026-04-18 21:25:29-05	2026-03-19 21:25:30-05	2026-03-19 21:25:29-05
406	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.b8912be86f256b1b9c0827d6d125c90e04fb05baf58c72f1a85815c60bd4cb34	2026-04-18 21:25:30-05	2026-03-19 21:38:23-05	2026-03-19 21:25:30-05
407	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.e10d013b1f547ba89fcbb920cb48389e9110fb70cb541449f6a2402ff47f081b	2026-04-18 21:38:23-05	2026-03-19 22:11:33-05	2026-03-19 21:38:23-05
408	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.b79663d7456ad4c4cff35979fe01fb76aba275b5680ef6885288a801902b5234	2026-04-18 22:11:33-05	2026-03-19 22:52:36-05	2026-03-19 22:11:33-05
409	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.722e485d21f06c4838b8038a2e43be429a007268b4fbad670121c78387fe36a6	2026-04-18 22:52:36-05	2026-03-19 23:22:41-05	2026-03-19 22:52:36-05
410	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.ed88db56b5f7c485c8a8d68a88ea7f90c3fecf0e6e412ccb7e72ab05216fa5ab	2026-04-18 23:22:41-05	2026-03-19 23:22:42-05	2026-03-19 23:22:41-05
411	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.41ef815d1ce70b53c3ef3c84660bbbea5cb6abbb01d474f29eef62271be86dc8	2026-04-18 23:22:42-05	2026-03-19 23:23:41-05	2026-03-19 23:22:42-05
412	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.4dece580e11eb78da85e546b06e039a8b40269d74e3f7c7a10d2ae206ae96f59	2026-04-18 23:23:41-05	2026-03-19 23:23:41-05	2026-03-19 23:23:41-05
413	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.20fe6f37a71990c2779f8b019c8f7bd666ea348d66e21b028d3cf1c8a8ecc8d2	2026-04-18 23:23:41-05	2026-03-19 23:24:41-05	2026-03-19 23:23:41-05
414	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.e5209fef22315626a8d8f1783a52bf8b1f4a520925762811e863cfe6055da178	2026-04-18 23:24:41-05	2026-03-19 23:24:42-05	2026-03-19 23:24:41-05
415	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.d46b3f3c85e3e87eafcc329b10dd9a30918daf8f07d1da17576ab2dc694a0f41	2026-04-18 23:24:42-05	2026-03-19 23:25:41-05	2026-03-19 23:24:42-05
416	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.5c8c2c65835525a8b6e702904216d431fbf9593e76f12f18af0bc1e26ca66d08	2026-04-18 23:25:41-05	2026-03-19 23:25:41-05	2026-03-19 23:25:41-05
417	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.533c9afc413dc5acc7caaac3a05407783bf02f552d48bbc5257d4b81010234af	2026-04-18 23:25:41-05	2026-03-19 23:26:07-05	2026-03-19 23:25:41-05
418	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.6881d5fac90d7b184184bb326ce1b1e8f6f481ff74eeed08ec3c4398a55c67cd	2026-04-18 23:26:07-05	2026-03-19 23:26:08-05	2026-03-19 23:26:07-05
419	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.e03cb3c806a5c69fc038f52440d05beda4d4de89f011f224c5e0c7793b7f9463	2026-04-18 23:26:08-05	2026-03-19 23:26:09-05	2026-03-19 23:26:08-05
420	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.a1a432cac34103be4ca89f0502ea47107814ee6b116b9a995e472b3a62747ee9	2026-04-18 23:26:09-05	2026-03-19 23:26:10-05	2026-03-19 23:26:09-05
421	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.98e5382ab1577ac5360c9de82ffd63fbb0d2561106154eec0830742f8110ef27	2026-04-18 23:26:10-05	2026-03-19 23:26:22-05	2026-03-19 23:26:10-05
422	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.fa25442b4414e6c98adc51ccbe95f7af636c9fbbce1dad9815981b1b2c8ebb27	2026-04-18 23:26:22-05	2026-03-19 23:26:22-05	2026-03-19 23:26:22-05
423	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.34b919a7e80282b1cf9cbc8dd30ca02ffa5cecd2a4e70fb8f41590fd771068ac	2026-04-18 23:26:22-05	2026-03-19 23:26:33-05	2026-03-19 23:26:22-05
424	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.9dbb08921388b5473d2bc641e87f869ac2a5ce09d33e99808a1ea547c07b5a4c	2026-04-18 23:26:33-05	2026-03-19 23:26:34-05	2026-03-19 23:26:33-05
425	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.f2c02d1de62ad8ec6ece19031d6ff7f957bdea53d45990cda6ea2072282d07d8	2026-04-18 23:26:34-05	2026-03-20 01:07:39-05	2026-03-19 23:26:34-05
426	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.bbd37a021a3deeae80e6ca3e4c96e55e6eb9e7162434e3dba8e7170e7108d76a	2026-04-19 01:07:39-05	2026-03-20 01:07:40-05	2026-03-20 01:07:39-05
427	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.ac641ab258422334ad580bad746b94ce87f9e41c0c44ce59644e58ad452ff309	2026-04-19 01:07:40-05	2026-03-20 01:07:41-05	2026-03-20 01:07:40-05
428	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.6b4da7e2581e254ba75dea5ee0127b1fe81ae5a1862c6f9aea4bef21843f1b8e	2026-04-19 01:07:41-05	2026-03-20 01:07:42-05	2026-03-20 01:07:41-05
429	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.90a1246d921c64a60d1ef40e3ae8c827622294db16cc37073f9b478e06d420e9	2026-04-19 01:07:42-05	2026-03-20 01:07:43-05	2026-03-20 01:07:42-05
430	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.0d165b3fed71fc5a74e2277dcb7babb80ee090521fa54bc57cc26cbf993dfdd6	2026-04-19 01:07:43-05	2026-03-20 01:07:44-05	2026-03-20 01:07:43-05
431	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.6e8421ae2bbc81f583c90a4f33190aa42ac4efe587c87dcb0d337617838d3db1	2026-04-19 01:07:44-05	2026-03-20 01:07:45-05	2026-03-20 01:07:44-05
432	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.5e44a0c8b48d60db996192d3a01b0f297200430a4eec28874101b6d633f137c0	2026-04-19 01:07:45-05	2026-03-20 01:07:47-05	2026-03-20 01:07:45-05
433	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.32f2142c18e806fc7408dd14badc6fe7c64f0c18fd2ff01d8d7d5ce02aa63f87	2026-04-19 01:07:47-05	2026-03-20 01:07:48-05	2026-03-20 01:07:47-05
434	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.e8db7a5f73de2e248bd37fe9fbf35800946bf596a1614425f5705627ade08b0a	2026-04-19 01:07:48-05	2026-03-20 01:07:49-05	2026-03-20 01:07:48-05
435	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.72209e49858f28f4e6ee6f6198f9077d1533a78de0e065abb5b89bfa04890275	2026-04-19 01:07:49-05	2026-03-20 01:07:50-05	2026-03-20 01:07:49-05
436	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.0b7ad537b112a5a7512029f7fbfd3bedd0ca004b0a1125bf89e1fb47caddbf4f	2026-04-19 01:07:54-05	2026-03-20 01:21:24-05	2026-03-20 01:07:54-05
437	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.30de40a5dd4ae7a49e63811cd43bea9e275181c77880e25cc63df7dccb064657	2026-04-19 01:21:24-05	2026-03-20 01:51:26-05	2026-03-20 01:21:24-05
438	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.520c80fb007ce964b2a9bd22cfc83e3cf90d1ca03ae9db5582d85cd9df90000b	2026-04-19 01:51:26-05	2026-03-20 01:51:27-05	2026-03-20 01:51:26-05
439	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.e283d60787f8c864ecd9f8a5087a78ed5cf8bbcf994268984eb65de128cc58fa	2026-04-19 01:51:27-05	2026-03-20 01:51:38-05	2026-03-20 01:51:27-05
440	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.b316777091fb784754562bc334bb1b876e2b4c8429acea2c710b49d20b7be71a	2026-04-19 01:51:38-05	2026-03-20 01:51:39-05	2026-03-20 01:51:38-05
441	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.961ac16cdc7cad58ab795736c0598e676bdf43c1a7c9d80820aaa81e5e9157a4	2026-04-19 01:51:39-05	2026-03-20 01:51:50-05	2026-03-20 01:51:39-05
442	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.aead4cb0e0cb475beee1f0704672ddad95dffa39ab8afb686e9504b59ae4ec65	2026-04-19 01:51:50-05	2026-03-20 01:51:50-05	2026-03-20 01:51:50-05
443	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.418f3c53d918ec95ebfaaa43d1c519ec2105cfc5a070ca6bee779ee50a26e9d8	2026-04-19 01:51:50-05	2026-03-20 01:52:02-05	2026-03-20 01:51:50-05
444	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.7670bf5c700d05422756b23b7d6adbdf5289bbbbb6d799bab91194139eda562d	2026-04-19 01:52:02-05	2026-03-20 01:52:03-05	2026-03-20 01:52:02-05
445	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.fe271da4c93257b29f40264397cd48368c1156c7f64b7f17994915ec5ce4e497	2026-04-19 01:52:03-05	2026-03-20 01:52:14-05	2026-03-20 01:52:03-05
446	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.c70b3d8c836fe0953c4cf5d1c5741f43409a5df45ac8e4f994892cfb5e4122f7	2026-04-19 01:52:14-05	2026-03-20 01:52:15-05	2026-03-20 01:52:14-05
447	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.3fceb333cbd2f75618635e616165ff5014858a39d84c1f9dd44ae8b9bcfa5d84	2026-04-19 01:52:15-05	2026-03-20 01:52:26-05	2026-03-20 01:52:15-05
448	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.3131500623278b3a5fa53d2f8eeff4a83de8eb19cb36a15d6eb6e6a674e5af69	2026-04-19 01:52:26-05	2026-03-20 01:52:27-05	2026-03-20 01:52:26-05
449	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.0dd44b2b496489c090dfa7c8bd581c3789abefee1203a01c3c266a1ee4ab94af	2026-04-19 01:52:27-05	2026-03-20 01:52:38-05	2026-03-20 01:52:27-05
450	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.ae4b9709fa7c7a9b2eed6ade4d1841725343001c8f592bc2df58214642e94dea	2026-04-19 01:52:38-05	2026-03-20 01:52:39-05	2026-03-20 01:52:38-05
451	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.38af1cec9622f55b7c8e1815be8f0b10cfc28c9c56e593742fa1b1b4609f66e7	2026-04-19 01:52:39-05	2026-03-20 01:52:50-05	2026-03-20 01:52:39-05
452	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.6eabc776c785172298bd0259cb8a46ea7d80c61e7babfb23ec6e1ee0436f6489	2026-04-19 01:52:50-05	2026-03-20 01:52:51-05	2026-03-20 01:52:50-05
453	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.257249508f765b2e2779ab7df35cd720e357c818bad69303289b90155377f81d	2026-04-19 01:52:51-05	2026-03-20 01:53:02-05	2026-03-20 01:52:51-05
454	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.faa185f212684bf9bdfc23f7da421dc70758f51cc229addf2e1d75849ecbc7a2	2026-04-19 01:53:02-05	2026-03-20 01:53:02-05	2026-03-20 01:53:02-05
455	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.643ba70fa13135e1989e0e1fa93367ebed76ee20296bef1f2c7264564e1272b6	2026-04-19 01:53:02-05	2026-03-20 01:53:14-05	2026-03-20 01:53:02-05
456	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.9593bf10547b8057c813f4184caafcffb51213a8dd3478b6a0c6f1650210d308	2026-04-19 01:53:14-05	2026-03-20 01:53:14-05	2026-03-20 01:53:14-05
457	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.ec6e552e6d20aa06d37e773b401060b773b4d8aaee3b3212a1d924962c8ef5a2	2026-04-19 01:53:14-05	2026-03-20 01:53:26-05	2026-03-20 01:53:14-05
458	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.8ac0503f04939072bca6450d2d536dcfcca6558aff891d534f41a40ab94e986f	2026-04-19 01:53:26-05	2026-03-20 01:53:27-05	2026-03-20 01:53:26-05
459	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.106b2bc577ed3d71e3de2c7d7ec2d96e74d84fb751368b1ca02933ccf94d5d65	2026-04-19 01:53:27-05	2026-03-20 01:53:38-05	2026-03-20 01:53:27-05
460	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.a012da9d98c0916e71a0fba6738c73ed5d4e25bd2f5e5503ce391e1fccf7218d	2026-04-19 01:53:38-05	2026-03-20 01:53:38-05	2026-03-20 01:53:38-05
461	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.1c0d3f8ccf287f326ec930c7faeaf47f255248fdd6437aa7214f21ed06436121	2026-04-19 01:53:38-05	2026-03-20 01:53:50-05	2026-03-20 01:53:38-05
462	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.3932c8e9e2ca5db9cd6ce762db773271279fbd2281a9469f3a9b7f02e3e53821	2026-04-19 01:53:50-05	2026-03-20 01:53:51-05	2026-03-20 01:53:50-05
463	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.0972803f11bc7a139c09a0846f204ec66e5c257bb75f09472d324c7970038cf5	2026-04-19 01:53:51-05	2026-03-20 01:54:02-05	2026-03-20 01:53:51-05
464	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.48417f5705eccb2d98133dec3886d58588d27ef284ea7e52dde34c0c90407662	2026-04-19 01:54:02-05	2026-03-20 01:54:03-05	2026-03-20 01:54:02-05
465	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.9423fc1bff1d7f191086d7d4c09f520fadf5f5b01e3240c824312fe088380ac8	2026-04-19 01:54:03-05	2026-03-20 01:54:14-05	2026-03-20 01:54:03-05
466	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.d38cfd059931531e132418f02ca34c748b4f9939f7a128330a37ea0bad723586	2026-04-19 01:54:14-05	2026-03-20 01:54:15-05	2026-03-20 01:54:14-05
467	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.9893ec0cb06df9cb58b4df294dcc2fc3dff869cdd7de220b9901a9e581efd66b	2026-04-19 01:54:15-05	2026-03-20 01:54:26-05	2026-03-20 01:54:15-05
468	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.a93aea5e3046211c512299196eb1955c82fabf9892732fff1064087d7e34ee86	2026-04-19 01:54:26-05	2026-03-20 01:54:27-05	2026-03-20 01:54:26-05
469	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.bc67025247d0af65f1912b04cce9d66c70d55d9f0f6cb2ca00240df987b429c1	2026-04-19 01:54:27-05	2026-03-20 01:54:38-05	2026-03-20 01:54:27-05
470	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.d80730e588eab6d109de91f45958fc3ebd9a7de27b20693363fa0d7a5813b9c5	2026-04-19 01:54:38-05	2026-03-20 01:54:38-05	2026-03-20 01:54:38-05
471	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.ed8bfc51330b715fb9a4e6b4c6cecc56cb0681b0ffb402fe21c157de48f0cf81	2026-04-19 01:54:38-05	2026-03-20 01:54:50-05	2026-03-20 01:54:38-05
472	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.e5c7b1f1e88572989985c10bd75c45ec036ec0e0a4801a4654f4618508ba287b	2026-04-19 01:54:50-05	2026-03-20 01:54:51-05	2026-03-20 01:54:50-05
473	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.e5b0f3ece6601a5b6a72b9f3babd4c749e8177a45f43bc7560ffcbd801b52e27	2026-04-19 01:54:51-05	2026-03-20 01:55:02-05	2026-03-20 01:54:51-05
474	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.6e46a8464924de3bdfb9ced1601469094855c5f135fa87ae1b6cf1e1f2c91c54	2026-04-19 01:55:02-05	2026-03-20 01:55:02-05	2026-03-20 01:55:02-05
475	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.8522bf1fdbbf61d719579ea17b694016d1b53ceb3d8a82d2902e397b8f83724f	2026-04-19 01:55:02-05	2026-03-20 01:55:14-05	2026-03-20 01:55:02-05
476	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.b18df12f14e777cb89006ff582824ee0b226f5cba54d24765fdc739c4c8a9cdc	2026-04-19 01:55:14-05	2026-03-20 01:55:14-05	2026-03-20 01:55:14-05
477	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.d6bc6bac3f9eecd9902df56cb2558b97b2e9143fffcfa6864966a4fbd78841e8	2026-04-19 01:55:14-05	2026-03-20 01:55:26-05	2026-03-20 01:55:14-05
478	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.47e32a0fcdf79d157659435a6a90be822ce161333b5943470fdd8a2ab6c33678	2026-04-19 01:55:26-05	2026-03-20 01:55:27-05	2026-03-20 01:55:26-05
479	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.74913fabeb202fe4e66725f0fe0e31a4cceef59aef685381da9b0ab2fa954e6f	2026-04-19 01:55:27-05	2026-03-20 01:55:38-05	2026-03-20 01:55:27-05
480	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.e8948eba57dae20c709492bbe3d2cdc2fcfcb2880820cae2902bee8455d26de7	2026-04-19 01:55:38-05	2026-03-20 01:55:39-05	2026-03-20 01:55:38-05
481	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.dd2f654181067749649126499f5cf39caad1c0c045b2e57d7bf61ff16d060359	2026-04-19 01:55:39-05	2026-03-20 01:55:50-05	2026-03-20 01:55:39-05
482	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.6ae9cc9909f092b8382788598653d47ef11279a1a9f72b74e560f42e98cb904f	2026-04-19 01:55:50-05	2026-03-20 01:55:50-05	2026-03-20 01:55:50-05
483	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.3848453f3878a9344b346202db25ca976ebd36f1b4a3d98760657b9495c7a1a5	2026-04-19 01:55:50-05	2026-03-20 01:56:02-05	2026-03-20 01:55:50-05
484	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.20479cfd6a93e9a6370a9ba0a8619d2e2de1c554f145a407438206a23d15c97e	2026-04-19 01:56:02-05	2026-03-20 01:56:02-05	2026-03-20 01:56:02-05
485	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.040812eb109fac6f48d0b3e88ba874930b8a8a3f1510b3f114f47cf8eddeec5d	2026-04-19 01:56:02-05	2026-03-20 01:56:14-05	2026-03-20 01:56:03-05
486	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.59a6e239238ba8955de5434c447ca65d73d10d1be1171117c13d3b6c8b546f8f	2026-04-19 01:56:14-05	2026-03-20 01:56:15-05	2026-03-20 01:56:14-05
487	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.82325346ae4e9f22435d572a2a778cb5fa08e1c739f03aa15d9e6a118db233d5	2026-04-19 01:56:15-05	2026-03-20 01:56:26-05	2026-03-20 01:56:15-05
488	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.d7cd3a69e52a967291b2245d13acfef282849afe14a37743233a103674b23652	2026-04-19 01:56:26-05	2026-03-20 01:56:27-05	2026-03-20 01:56:26-05
489	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.901e437dec751aec0ef8c24ad83b5427423418453a57649e8b317f1aa2cf9d91	2026-04-19 01:56:27-05	2026-03-20 01:56:38-05	2026-03-20 01:56:27-05
490	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.a9678200b464370c3498014b3b8dd06e39dffa427ea17c334f6351434f6cbf34	2026-04-19 01:56:38-05	2026-03-20 01:56:39-05	2026-03-20 01:56:38-05
491	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.f896686177e4f2db0fb2fe03275134782125cf69575e938597e9daff5330e78e	2026-04-19 01:56:39-05	2026-03-20 01:56:50-05	2026-03-20 01:56:39-05
492	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.259d3fef79801f119070d98911056a17a971efc8e0559b5082d76b6afa5a54d1	2026-04-19 01:56:50-05	2026-03-20 01:56:51-05	2026-03-20 01:56:50-05
493	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.3b9b2e8c3eb5c8fee3cbc74cd0f622ebd2edab123d9d65f1827d40e67b4d0398	2026-04-19 01:56:51-05	2026-03-20 01:57:02-05	2026-03-20 01:56:51-05
494	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.40038d1c1d429748f549f66b552b15e262a99def9e811248b00e2b02e6cebd66	2026-04-19 01:57:02-05	2026-03-20 01:57:02-05	2026-03-20 01:57:02-05
495	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.5b9d3cae257c1996d107bf347b9a2b55c2ec80912c76e7ec7224e028c2113318	2026-04-19 01:57:02-05	2026-03-20 01:57:14-05	2026-03-20 01:57:02-05
496	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.c8e7c0821f79a22b9ff287b3268323887bfe23ff180f79d36fd910d16b029eb9	2026-04-19 01:57:14-05	2026-03-20 01:57:15-05	2026-03-20 01:57:14-05
497	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.8a7f1dd9783bcfb734552bf38cceb7fedd695a4d7a6de833f58cb79f31d614c2	2026-04-19 01:57:15-05	2026-03-20 01:57:26-05	2026-03-20 01:57:15-05
498	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.4e7eebda469cd737b43e9e35cf8e33ce1ff72e2f0a6248faf42088a08d0de841	2026-04-19 01:57:26-05	2026-03-20 01:57:27-05	2026-03-20 01:57:26-05
499	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.0bcf898903871b5fff7c6359eba7cdf7ba201a59d4527752c82815e52f54461c	2026-04-19 01:57:27-05	2026-03-20 01:57:38-05	2026-03-20 01:57:27-05
500	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.01d0400eb23e240a4f6c02dd8e62f18a8d99cd8d434cf70c6caa0074df81ca04	2026-04-19 01:57:38-05	2026-03-20 01:57:39-05	2026-03-20 01:57:38-05
501	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.98a43a279328f3a19e55af053ff09bfbcf5ba4b44040ba0c9b16fa4da84ef871	2026-04-19 01:57:39-05	2026-03-20 01:57:50-05	2026-03-20 01:57:39-05
502	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.8b5c2a4ca5abb2e558bfd11224c08b36201b4ffdac06e4acc0e274c9509f85e4	2026-04-19 01:57:50-05	2026-03-20 01:57:50-05	2026-03-20 01:57:50-05
503	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.231d659ce88c64cfd4df98f9c2bb20a90db76a8a291d64200bdb778fd80b1fc0	2026-04-19 01:57:50-05	2026-03-20 01:58:02-05	2026-03-20 01:57:50-05
504	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.78546b389c44ed9b8403843181de1b0c86d8a779de08f7a6092414946dacd5de	2026-04-19 01:58:02-05	2026-03-20 01:58:03-05	2026-03-20 01:58:02-05
505	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.15e4c31f2ad5abb68cdb4353e41c82c33c95220d201c22a7404f1f0388d76600	2026-04-19 01:58:03-05	2026-03-20 01:58:14-05	2026-03-20 01:58:03-05
506	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.7842a09167915c7376f7bd3a17fc4278d121adfcdbb00faa38590f79d9762617	2026-04-19 01:58:14-05	2026-03-20 01:58:14-05	2026-03-20 01:58:14-05
507	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.f4147aca873933fb556b595b20123e2cd7992de792b4a770258c77b57b85487f	2026-04-19 01:58:14-05	2026-03-20 01:58:26-05	2026-03-20 01:58:14-05
508	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.f4b3b77d70ea478fc52e3de6d53a2a20074c01afb415d1305dde85773e4c1006	2026-04-19 01:58:26-05	2026-03-20 01:58:27-05	2026-03-20 01:58:26-05
509	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.78d3c80babbffefe05ed4e364ae284b64fa23fd207016d157bab27447a307fc7	2026-04-19 01:58:27-05	2026-03-20 01:58:38-05	2026-03-20 01:58:27-05
510	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.386d8747340075f7eadaa1561e65c5bc90da0ed3d987ec78963b97b6f561fa76	2026-04-19 01:58:38-05	2026-03-20 01:58:38-05	2026-03-20 01:58:38-05
511	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.205b923a3bbbaf71f618c7d78c054e966bfeea31e4dc727d06e171bafc4176eb	2026-04-19 01:58:38-05	2026-03-20 01:58:50-05	2026-03-20 01:58:38-05
512	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.eafa08de785d13aff48bb5baa65be285aa57ff19d037e57c889ccf5411265229	2026-04-19 01:58:50-05	2026-03-20 01:58:51-05	2026-03-20 01:58:50-05
513	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.75a5269b8f11f7244a2c206b2997dc06f51fd59b43faf9035f1c5d26c5e7203c	2026-04-19 01:58:51-05	2026-03-20 01:59:02-05	2026-03-20 01:58:51-05
514	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.6d3bbc22b61489e581602ec03763a39a7ea99a6356daf051af810b28f366f057	2026-04-19 01:59:02-05	2026-03-20 01:59:02-05	2026-03-20 01:59:02-05
515	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.8ef01e88c84615ec8a4cd44fc831ace19c7e0c86af6235f5645a1952960f074f	2026-04-19 01:59:02-05	2026-03-20 01:59:14-05	2026-03-20 01:59:02-05
516	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.009fd2e36a5bf7ef4938f26cab8ae6b28201c095cb407bdbccb2d4d76f861922	2026-04-19 01:59:14-05	2026-03-20 01:59:14-05	2026-03-20 01:59:14-05
517	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.9e7eca3c8a4618a31235b4528a8a31677fdd0670606a57e4f7da06152b7bc288	2026-04-19 01:59:14-05	2026-03-20 01:59:26-05	2026-03-20 01:59:14-05
518	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.e0b884bd60960ada0b0da59cf2f985a92eeeb1e971d45e8ec75e2ccf1cb36621	2026-04-19 01:59:26-05	2026-03-20 01:59:27-05	2026-03-20 01:59:26-05
519	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.9abb636681094cdca96560997fc9deeefad2a62a69800c509db67f1fa1f47c54	2026-04-19 01:59:27-05	2026-03-20 01:59:38-05	2026-03-20 01:59:27-05
520	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.1afe1185bfc1fb626a1a10aafbec597ad771f8cc5fd010dcd6cc5f4e9241c0dc	2026-04-19 01:59:38-05	2026-03-20 01:59:39-05	2026-03-20 01:59:38-05
521	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.50301bdfff264557b5f3e65bf83a34ea30a00e72f00ed543aa7deddc92b89134	2026-04-19 01:59:39-05	2026-03-20 01:59:50-05	2026-03-20 01:59:39-05
522	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.9dddbaad94d5913ccc2c2b7a78a7d0ce7e4ed001cc4ca43675363f260f31386c	2026-04-19 01:59:50-05	2026-03-20 01:59:50-05	2026-03-20 01:59:50-05
523	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.ed905435fc74bdcf6873a66ac875ddd5703b71bd05f5fc7e174fe4f3ac018ebd	2026-04-19 01:59:50-05	2026-03-20 02:00:02-05	2026-03-20 01:59:50-05
524	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.0323e325722df468af67bc0318f1200f2a81aed8d544e77aa31a9a982a4e3a3a	2026-04-19 02:00:02-05	2026-03-20 02:00:02-05	2026-03-20 02:00:02-05
525	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.cd1cb0053ebe015c011d664771291e60b8ad1400339c19c5649c1a42017e3d17	2026-04-19 02:00:02-05	2026-03-20 02:00:14-05	2026-03-20 02:00:02-05
526	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.595b99d0d54fb70792252d266b6ea79a686b84f355708a36407c493207c555c3	2026-04-19 02:00:14-05	2026-03-20 02:00:14-05	2026-03-20 02:00:14-05
527	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.c871ca812581327b06445d7d268a870d32d39c70826bb849de45608c4e793c5d	2026-04-19 02:00:14-05	2026-03-20 02:00:26-05	2026-03-20 02:00:14-05
528	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.55b7bcff20ebc984acf2b433a2883d24d7f96b5ef5e83834f7243e2f3782d2e2	2026-04-19 02:00:26-05	2026-03-20 02:00:27-05	2026-03-20 02:00:26-05
529	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.1522538185625c97ca2a38817eb9b6f9f72b29f95ccb01b0b72a3a71287b5c2b	2026-04-19 02:00:27-05	2026-03-20 02:00:38-05	2026-03-20 02:00:27-05
530	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.1f890b33f4421e196023bb9d5e0bb9638ef6105d6a05eeb31e0325c40260149e	2026-04-19 02:00:38-05	2026-03-20 02:00:38-05	2026-03-20 02:00:38-05
531	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.fa7a52626abb2fbc92060a06a04df357cc441d1cdb4f92c5058079abc6fb5a98	2026-04-19 02:00:38-05	2026-03-20 02:00:50-05	2026-03-20 02:00:38-05
532	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.def8de4e23a97bd7bec965fbe25ebaa3069a058e38b5574de013ce57c5cbf4c7	2026-04-19 02:00:50-05	2026-03-20 02:00:51-05	2026-03-20 02:00:50-05
533	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.792bf62a2eab478cda1afce9783ddace3e4578661ae82e4ceaefe2e233ffd673	2026-04-19 02:00:51-05	2026-03-20 02:01:02-05	2026-03-20 02:00:51-05
534	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.621a3f6919d078cbe36f41055566aebd6b6e61b44b8260c14fcaec0d2b1b4397	2026-04-19 02:01:02-05	2026-03-20 02:01:03-05	2026-03-20 02:01:02-05
535	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.b1b61e82258c2dc35c717d47553f4c7db41db950a8287eea402c4e4d0adf93f6	2026-04-19 02:01:03-05	2026-03-20 02:01:14-05	2026-03-20 02:01:03-05
536	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.7979d6c826235a99afb6317af7c2fdb915b12c5ebfd3bb9ddfa01872dbc87fa7	2026-04-19 02:01:14-05	2026-03-20 02:01:14-05	2026-03-20 02:01:14-05
537	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.d327a4e945b0a681ce9e00d62b6b83ed966e54c39ad48eda1dbf3d2889d8a624	2026-04-19 02:01:14-05	2026-03-20 02:01:26-05	2026-03-20 02:01:14-05
538	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.053eda364cce4bb0c7ce1541d8625afcda6d8d6600111cfd30ad6eb8832ce943	2026-04-19 02:01:26-05	2026-03-20 02:01:27-05	2026-03-20 02:01:26-05
539	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.5098378047f1b50e17392d54176261b485e1d987032419acb51966258c2eb127	2026-04-19 02:01:27-05	2026-03-20 02:01:38-05	2026-03-20 02:01:27-05
540	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.aabf8b3b9dfffe162edbd59a054d6e44cfa00e4b2f7b44c74b22bfeb5f04d9af	2026-04-19 02:01:38-05	2026-03-20 02:01:39-05	2026-03-20 02:01:38-05
541	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.7d9a2b3941cc9c53cf7f8f52fe1ea89275fb87f0554a7d1726f9954a32cc05f7	2026-04-19 02:01:39-05	2026-03-20 02:01:50-05	2026-03-20 02:01:39-05
542	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.1d0ab3b0da3c212a0dd0823009b498fd7efc2efd1752d281db087262504a6252	2026-04-19 02:01:50-05	2026-03-20 02:01:50-05	2026-03-20 02:01:50-05
543	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.5d55e4e2f8493e42d5722e079dcf2cebea8dc678c958290f25f1b26df51262a5	2026-04-19 02:01:50-05	2026-03-20 02:02:02-05	2026-03-20 02:01:50-05
544	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.4c7c1423a60f8f07445c2522e2ae41cabb0ae27a1b7b3120e305fdc28f5c96f9	2026-04-19 02:02:02-05	2026-03-20 02:02:02-05	2026-03-20 02:02:02-05
545	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.409cc7d0f56f02c3d2d8fc32d29096d1332efddf766de8a32e99047cda9b8e31	2026-04-19 02:02:02-05	2026-03-20 02:02:14-05	2026-03-20 02:02:02-05
546	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.598dacf871dc1b5fec5dcfc5f261111c5ec19697c4f706277628db3b061c628b	2026-04-19 02:02:14-05	2026-03-20 02:02:15-05	2026-03-20 02:02:14-05
547	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.aa15ce7b0f3bc6b8d627f3bb14a61f815bc3420a66eb2de314379e4352fb0257	2026-04-19 02:02:15-05	2026-03-20 02:02:26-05	2026-03-20 02:02:15-05
548	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.83062d52f4e539cebd5b98c150a6512c2c15aca453a63fdc47ccb17b05421360	2026-04-19 02:02:26-05	2026-03-20 02:02:27-05	2026-03-20 02:02:26-05
549	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.78398c0e2c1398bf6a03d93fe2f451f60f97a6679e520eac398f2f768280a42b	2026-04-19 02:02:27-05	2026-03-20 02:02:38-05	2026-03-20 02:02:27-05
550	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.0d1653c9502b9ba54d358d69080a6a23233c04afbb02799e63c5a13073049966	2026-04-19 02:02:38-05	2026-03-20 02:02:39-05	2026-03-20 02:02:38-05
551	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.a5f6088de7dc502c9881c65a197ff3e13f416d0e1a203daa74a151fc5b86f91d	2026-04-19 02:02:39-05	2026-03-20 02:02:50-05	2026-03-20 02:02:39-05
552	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.c105eee281a7ce63275a3aad541d499e1e59dd19223316ff8f5a636f88c43b8a	2026-04-19 02:02:50-05	2026-03-20 02:02:50-05	2026-03-20 02:02:50-05
553	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.3df1a06f45e5a5e2ff4f2480a8edf5478699908178a13adb0fc8715d6fc2464f	2026-04-19 02:02:50-05	2026-03-20 02:03:02-05	2026-03-20 02:02:50-05
554	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.13e281c1c778041e23ee3627fec8a9352eca36a8d72260b9e1fec01aef511938	2026-04-19 02:03:02-05	2026-03-20 02:03:03-05	2026-03-20 02:03:02-05
555	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.33664ee7dc52c0305f3d6171dd9e5b81244e19e7181084cc6e33964ee32a97bb	2026-04-19 02:03:03-05	2026-03-20 02:03:14-05	2026-03-20 02:03:03-05
556	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.828eb4f1a2261d4baff88092852239505dc1e4e272bd94e3de0941cd31c79ffd	2026-04-19 02:03:14-05	2026-03-20 02:03:14-05	2026-03-20 02:03:14-05
557	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.783ee3b62ebf65efebd8acd2aa5d155e5500c75ced70a39a5ba453860c2463b9	2026-04-19 02:03:14-05	2026-03-20 02:03:26-05	2026-03-20 02:03:14-05
558	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.80f3b8489f2811a5ef620ca411d28259aba743180f25b0de23dc27923d0f9e26	2026-04-19 02:03:26-05	2026-03-20 02:03:27-05	2026-03-20 02:03:26-05
559	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.ad40be166fe198f9079340f173b6f0b39505cda2d4b4aec3bbd9d8f22a3ec65f	2026-04-19 02:03:27-05	2026-03-20 02:03:38-05	2026-03-20 02:03:27-05
560	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.17f5f775100997557b89ad88129e3e1f04d5ae7017fa9651be6a1017871940e7	2026-04-19 02:03:38-05	2026-03-20 02:03:38-05	2026-03-20 02:03:38-05
561	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.8c01004d8da0a0d89e553b7bda583aa0ba69b6601dc563b7d0629f89330f016b	2026-04-19 02:03:38-05	2026-03-20 02:03:50-05	2026-03-20 02:03:38-05
562	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.1a2f661aea5f3c619341f098da336204e81383fbd247e95d6a68032167094cd5	2026-04-19 02:03:50-05	2026-03-20 02:03:51-05	2026-03-20 02:03:50-05
563	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.2c545e0a416c0a445b527748deea280c76a2b92685209281c8bc885cfbaa42f6	2026-04-19 02:03:51-05	2026-03-20 02:04:02-05	2026-03-20 02:03:51-05
564	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.092ae1e30fb3dfdea2c5483ed7ce9c02301881375fb326cd62448d0373e1ff5a	2026-04-19 02:04:02-05	2026-03-20 02:04:03-05	2026-03-20 02:04:02-05
565	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.9d2224bc9e735899ba93e934d14db282c67ec711a77afa6fa406bbd2120a47a6	2026-04-19 02:04:03-05	2026-03-20 02:04:14-05	2026-03-20 02:04:03-05
566	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.a000ff88562314fc285683ce5779769f09c3589ff0d39e86de03b309929b9cfd	2026-04-19 02:04:14-05	2026-03-20 02:04:15-05	2026-03-20 02:04:14-05
567	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.57d96836f1fa9f36292bf5eaf5cb663d22d82ad817e9b18292452c0bcd02945a	2026-04-19 02:04:15-05	2026-03-20 02:04:26-05	2026-03-20 02:04:15-05
568	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.f22533ae48094ed53d0c5d7cef1cf5bdae4532d58c3d16700fd76725c9d6c3d6	2026-04-19 02:04:26-05	2026-03-20 02:04:27-05	2026-03-20 02:04:26-05
569	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.a7308bc8514f2664d9dbd7ce1720acaa94187f7d3350839ef1e50159268b85b1	2026-04-19 02:04:27-05	2026-03-20 02:04:38-05	2026-03-20 02:04:27-05
570	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.1e1bb5d561df9ff20f3f9365228038a430e8aa3c648d84556718ac12abec6b04	2026-04-19 02:04:38-05	2026-03-20 02:04:38-05	2026-03-20 02:04:38-05
571	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.b6a20360ff19538dce4dc7f42ad361cac6af40553d4c742c3b5c40a7c7008443	2026-04-19 02:04:38-05	2026-03-20 02:04:50-05	2026-03-20 02:04:38-05
572	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.222d2a7b50e99a95c37b1411e540b353137340102ceaba026bf000c713509e92	2026-04-19 02:04:50-05	2026-03-20 02:04:51-05	2026-03-20 02:04:50-05
573	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.ba0fbd9265e50f7e3c2a142fa28b718221144917204696fdeb67a5a2d1c242dc	2026-04-19 02:04:51-05	2026-03-20 02:05:02-05	2026-03-20 02:04:51-05
574	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.915b6d0e60ae099081b565f91f4043857a9da203c35ba8e6d5f612ac71145612	2026-04-19 02:05:02-05	2026-03-20 02:05:03-05	2026-03-20 02:05:02-05
575	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.70626abb892886b6555d357554479dc41d5d6265f62d72b2e06ab41695094763	2026-04-19 02:05:03-05	2026-03-20 02:05:14-05	2026-03-20 02:05:03-05
576	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.5678ee4736f3cdba0c317ed37936ee7f6d53bf000a027e01c09c5930c14a5a4a	2026-04-19 02:05:14-05	2026-03-20 02:05:15-05	2026-03-20 02:05:14-05
577	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.b23e4c62057ee77ada1ecd84508ce4c8f7acc67c057f322e5fb135da8fc9b735	2026-04-19 02:05:15-05	2026-03-20 02:05:26-05	2026-03-20 02:05:15-05
578	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.664cde587fdeeaabb4a619cfd0909b449da709cdf5f0539541fcb2caf74aa00b	2026-04-19 02:05:26-05	2026-03-20 02:05:27-05	2026-03-20 02:05:26-05
579	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.4c6623023928d103f68412033ca1b4274a11dbe07b024741c41d892caed0ad6e	2026-04-19 02:05:27-05	2026-03-20 02:05:38-05	2026-03-20 02:05:27-05
580	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.b4a96440260a42f61e863a42f3a8809578551c2eeb755a7468a3f99e60c89678	2026-04-19 02:05:38-05	2026-03-20 02:05:39-05	2026-03-20 02:05:38-05
581	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.edce4bcc760874576e0a011f772d3623ba3cc5ff171b6bbf43308de348c70758	2026-04-19 02:05:39-05	2026-03-20 02:05:50-05	2026-03-20 02:05:39-05
582	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.596a03c46819b6c826eda08c73a1ca6636a7a11bf4b9369a16ef40d5f825a194	2026-04-19 02:05:50-05	2026-03-20 02:05:51-05	2026-03-20 02:05:50-05
583	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.d3b8d075c4861b4a7e360727b8ba2153cc423fe17f96c50e11205825b5748fd3	2026-04-19 02:05:51-05	2026-03-20 02:06:02-05	2026-03-20 02:05:51-05
584	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.0e0715b1fdaf43512b2886e31f5dfdd2dee6d368437f414348d8114bfd3e33ca	2026-04-19 02:06:02-05	2026-03-20 02:06:03-05	2026-03-20 02:06:02-05
585	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.6ed4906f2f2da22bb3c59c266cb26be8eab1d1f288207387897c5ded69c22568	2026-04-19 02:06:03-05	2026-03-20 02:06:14-05	2026-03-20 02:06:03-05
586	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.2c67144ecbc1a24ff8db4a309d8d75454c5718bb8c2c2ffc664f6f273c9bc1b7	2026-04-19 02:06:14-05	2026-03-20 02:06:15-05	2026-03-20 02:06:14-05
587	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.b32b1e48438ed85c4c0dda5821cc383656e5a5fd110a7725585221aefad3d26b	2026-04-19 02:06:15-05	2026-03-20 02:06:26-05	2026-03-20 02:06:15-05
588	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.682d0859895f80adbb7a75fb4f06b0d2407aeaefa0ff522fe32d5ed80963e3e8	2026-04-19 02:06:26-05	2026-03-20 02:06:27-05	2026-03-20 02:06:26-05
589	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.f573265a6b8c7c345f43a94b9e020a8cd5d80752c1819a8ee13f39c71445dd8e	2026-04-19 02:06:27-05	2026-03-20 02:06:38-05	2026-03-20 02:06:27-05
590	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.317cc8c4eec438448b15dfe6036903dffee841f216dedbecb4b098323706e0e9	2026-04-19 02:06:38-05	2026-03-20 02:06:39-05	2026-03-20 02:06:38-05
591	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.9b3f01ee83e2bbcbba56118707bef117e324b5012c5794e1581aa0f198731327	2026-04-19 02:06:39-05	2026-03-20 02:06:50-05	2026-03-20 02:06:39-05
592	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.c805f24bb47c1aa90887c1db8b943b6420b369c545ee98e180781caefb1cf2b6	2026-04-19 02:06:50-05	2026-03-20 02:06:51-05	2026-03-20 02:06:50-05
593	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.0f262dd9846741f30b0facea3e96bc4ef6ac20af183cf70110e89a1dfd28e791	2026-04-19 02:06:51-05	2026-03-20 02:07:02-05	2026-03-20 02:06:51-05
594	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.0e9cf77baf5357a7955e888373f66872e4574880f23a846ddd6e10900570facb	2026-04-19 02:07:02-05	2026-03-20 02:07:03-05	2026-03-20 02:07:02-05
595	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.1b2129aee727583c323209389dca774a78309e847d318fb704efa31cca981048	2026-04-19 02:07:03-05	2026-03-20 02:07:14-05	2026-03-20 02:07:03-05
596	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.f205a0b5948ef46df3a2b1d329838091e4bbcfda0a8cfee7f96b435cc68c2d74	2026-04-19 02:07:14-05	2026-03-20 02:07:14-05	2026-03-20 02:07:14-05
597	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.b2a4be6b32c7abe1c847f326c9c0ff266d3ef84daa19833a334158f5dd87f1af	2026-04-19 02:07:14-05	2026-03-20 02:07:16-05	2026-03-20 02:07:14-05
598	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.934a3f42fc6b7e3267a1c7dbf7c76d445a79093723f1292b5518965c33242060	2026-04-19 02:07:16-05	2026-03-20 02:07:17-05	2026-03-20 02:07:16-05
599	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.51e259e36bd280fa817844244f5d37f25a20fe5b6477355d1f0c96c18e78a240	2026-04-19 02:07:17-05	2026-03-20 02:07:18-05	2026-03-20 02:07:17-05
600	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.27e8dfc829ee087428806dd766a2791718242ab7c9b2fc47707f50f2a4df0dc8	2026-04-19 02:07:18-05	2026-03-20 02:07:18-05	2026-03-20 02:07:18-05
601	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.0058a49a2692b9a4c653691cb7d69e6ea76d0739677337d90c921924880fd811	2026-04-19 02:07:18-05	2026-03-20 02:07:19-05	2026-03-20 02:07:18-05
602	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.795d043721c4819593ae2f89b3dad5b71c88582fe00488e904dd0527012b9bdd	2026-04-19 02:07:19-05	2026-03-20 02:07:20-05	2026-03-20 02:07:19-05
603	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.dd4fd06f7bdbbfdb7e56f73238a64686691c08787a4fd9e8573d0b2acde2af4c	2026-04-19 02:07:20-05	2026-03-20 02:07:21-05	2026-03-20 02:07:20-05
604	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.570667ec5055912972cb606d0f161bef4bcfc723d60b3df98a3ab7d6e51aa3bf	2026-04-19 02:07:21-05	2026-03-20 02:07:22-05	2026-03-20 02:07:21-05
605	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.d580e1a00302e9cb6677c118e2671b78a17f941503f7d8174bdc39a35111dddf	2026-04-19 02:07:22-05	2026-03-20 02:07:23-05	2026-03-20 02:07:22-05
606	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.a6011046d5c74e167c59bb97362e8b194471c6afdf1a8ef46cf9ccb56a1a3d39	2026-04-19 02:07:23-05	2026-03-20 02:07:23-05	2026-03-20 02:07:23-05
607	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.edbdeb8e632b8ebcfba6c38428ade800618a9fc50dc9550f3ca08351e514654a	2026-04-19 02:07:23-05	2026-03-20 02:07:24-05	2026-03-20 02:07:23-05
608	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.141da1ca9c1455a1d0dd83a473ba17f2920734eebcda63cf6fe25152b5217fea	2026-04-19 02:07:24-05	2026-03-20 02:07:25-05	2026-03-20 02:07:24-05
609	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.8910e8a5692f458b1ef59bdb09951d813ea3752fc24ffa2667f46d7d8b604833	2026-04-19 02:07:25-05	2026-03-20 02:07:26-05	2026-03-20 02:07:25-05
610	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.bfad33a9589572e204b5e7b4777371771a234ae0d0351191bc884d7bce0ed8b5	2026-04-19 02:07:26-05	2026-03-20 02:07:27-05	2026-03-20 02:07:26-05
611	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.5414884f1dbd7af0149f7373e98dc2667b88d303aaa903d3ec886876c527af94	2026-04-19 02:07:27-05	2026-03-20 02:07:28-05	2026-03-20 02:07:27-05
612	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.7b30c1692b1b82ad347c41cca22f7bc9c6e863015280ff818210947ea7148588	2026-04-19 02:07:28-05	2026-03-20 02:07:29-05	2026-03-20 02:07:28-05
613	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.8e700411f23de355cb30da4cd0d49de398d361664e2824069e780c9811967de8	2026-04-19 02:07:29-05	2026-03-20 02:07:30-05	2026-03-20 02:07:29-05
614	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.e11799615a23dff3f35e05044919f104cce974dc2eb49c6cf10a30d59336e0dd	2026-04-19 02:07:30-05	2026-03-20 02:07:31-05	2026-03-20 02:07:30-05
615	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.2828096111c3c741c40b733aeb083a72b18b8753750fedd0a332474033ee3e22	2026-04-19 02:07:31-05	2026-03-20 02:07:32-05	2026-03-20 02:07:31-05
616	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.5508683c5d7b62e4c9b43667a0fa1c1bc56853ef1660a59f5e6343c3fd26dfdc	2026-04-19 02:07:32-05	2026-03-20 02:07:33-05	2026-03-20 02:07:32-05
617	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.98e566082a59a18876e961543fff1cdaa32519a668c4e5ddef25f396485126ba	2026-04-19 02:07:33-05	2026-03-20 02:07:34-05	2026-03-20 02:07:33-05
618	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.f96a31174945fc5bcba84c2749db987a5384e938078dea37c05e2254ce6f2214	2026-04-19 02:07:34-05	2026-03-20 02:07:35-05	2026-03-20 02:07:34-05
619	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.259986efcbfa22399f63480345dbc923d403b542beb1a3126765fa749c733ce8	2026-04-19 02:07:35-05	2026-03-20 02:07:36-05	2026-03-20 02:07:35-05
620	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.87bba424316e877fc6daab4883ce15084fb3b709876e605f06a31e282fb59ae8	2026-04-19 02:07:36-05	2026-03-20 02:07:37-05	2026-03-20 02:07:36-05
621	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.b3de73a5e2becc075a1d21a0ed3e307a59860e33bdc7cff832bfd3a2bf930670	2026-04-19 02:07:37-05	2026-03-20 02:07:38-05	2026-03-20 02:07:37-05
622	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.4b3c0121b139cef33d0007fdc03ea49a41b9b63ee9bbc1ac4d389f3fd30eea3f	2026-04-19 02:07:38-05	2026-03-20 02:07:39-05	2026-03-20 02:07:38-05
623	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.92057c0fef0d3e05618bb5f252f44e9c4d8023082a1aaa44c8de02a22b59b1ad	2026-04-19 02:07:39-05	2026-03-20 02:07:40-05	2026-03-20 02:07:39-05
624	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.1e820bceccda4da6b4cfe800ec9c3f447511bcab7be8bf9a83b5ff3150c876ff	2026-04-19 02:07:40-05	2026-03-20 02:07:41-05	2026-03-20 02:07:40-05
625	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.cf4a117dbc979a88c6f86929af17a8d36ae32de578ec5850be667445baf4c6f5	2026-04-19 02:07:41-05	2026-03-20 02:07:42-05	2026-03-20 02:07:41-05
626	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.2be5b4ca3cd6a01c6332a416d004f9c31c8f6293b44d0c9cba0533b98eb0bb33	2026-04-19 02:07:42-05	2026-03-20 02:07:44-05	2026-03-20 02:07:42-05
627	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.b620ced7068dda93e2e1b58a55896bbf74bb9541c943ca6fa2ecf86d069c5513	2026-04-19 02:07:44-05	2026-03-20 02:07:45-05	2026-03-20 02:07:44-05
628	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.36f2ba0d90e0efeeb3bf6ea7d07b619105b08a5ca08161246a77a814616b5b92	2026-04-19 02:07:45-05	2026-03-20 02:07:46-05	2026-03-20 02:07:45-05
629	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.029e93c6c9db461ad390f1bec6c4d0ddea0396a7d7071552e1785ed9ad9a4009	2026-04-19 02:07:46-05	2026-03-20 02:07:47-05	2026-03-20 02:07:46-05
630	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.79182293c7c6637568d25c391f75a7c7eb48ed40570b0a5f32ce70ed3381b6bf	2026-04-19 02:07:47-05	2026-03-20 02:07:48-05	2026-03-20 02:07:47-05
631	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.2290292b7c33a20d6609d1fa238e17554e84edd4399435516a8a3ab75625fe4f	2026-04-19 02:07:48-05	2026-03-20 02:07:48-05	2026-03-20 02:07:48-05
632	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.698b9540e4e0f9221288aa06e67dadb9db74badf8e84789de2b51f2ec502a5fe	2026-04-19 02:07:48-05	2026-03-20 02:07:49-05	2026-03-20 02:07:48-05
633	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.1f49d04609fddff22160327d98c2d1c74e8947882532c91ceb66799cb77b91ce	2026-04-19 02:07:49-05	2026-03-20 02:07:50-05	2026-03-20 02:07:49-05
634	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.c3952fe28bda9d602520232e9468c8386d90871c5ab34698beac29d11b62b61e	2026-04-19 02:07:50-05	2026-03-20 02:07:51-05	2026-03-20 02:07:50-05
635	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.71202c8d654029acd64c72c915d9cc13fb89a1417521414ab740992d2a348647	2026-04-19 02:07:51-05	2026-03-20 02:07:52-05	2026-03-20 02:07:51-05
636	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.b181e4b3fca83bbfb99bdb431ef629c9aa655aeab6c3c2344e7e4c20e18bda02	2026-04-19 02:07:52-05	2026-03-20 02:07:53-05	2026-03-20 02:07:52-05
637	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.76822fa123a3737e07b6d81bd0ccbb9c1a6bae66332f105d480d0fc70c3b1964	2026-04-19 02:07:53-05	2026-03-20 02:07:54-05	2026-03-20 02:07:53-05
638	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.9a5aaae92109cf84c938c7f469070fd7d56408f4424567b180b77a8d40e7de30	2026-04-19 02:07:54-05	2026-03-20 02:07:55-05	2026-03-20 02:07:54-05
639	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.e693c00a5882cf1038d437a4c5bdff03cb26d717dbe99c7dd877061c287a59ef	2026-04-19 02:07:55-05	2026-03-20 02:07:56-05	2026-03-20 02:07:55-05
640	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.6e9dad18faf07e8dc246d8f42d4a99f0499e0f3a3a369836efa3fec98ca14638	2026-04-19 02:07:56-05	2026-03-20 02:07:57-05	2026-03-20 02:07:56-05
641	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.a397142ad4d6e07bb4c39761e5e662d7809e4ed5a566bf0425a830c7e8335e51	2026-04-19 02:07:57-05	2026-03-20 02:07:59-05	2026-03-20 02:07:57-05
642	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.f93faf7ccc705dd7c96293d143b77dd92087fc990455885366f15973328ceae8	2026-04-19 02:07:59-05	2026-03-20 02:08:00-05	2026-03-20 02:07:59-05
643	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.c1d350bdc4b52a0ef8af74bbf9489ce8802926db6645fab0f49eabc09bfd891c	2026-04-19 02:08:00-05	2026-03-20 02:08:01-05	2026-03-20 02:08:00-05
644	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.aabdfd1c1f8197eb2144a4d9681627b6e4da5ae3f32c6c5cc9473634365ac479	2026-04-19 02:08:01-05	2026-03-20 02:08:02-05	2026-03-20 02:08:01-05
645	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.677780228faf0e52360198edb2cba7dc430f253636e051de7a597cf67216db9a	2026-04-19 02:08:02-05	2026-03-20 02:08:04-05	2026-03-20 02:08:02-05
646	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.8b1ea4b0c19720851c376a0057be27776ae4bd43b8bea7f922ea515d39509d8d	2026-04-19 02:08:04-05	2026-03-20 02:08:05-05	2026-03-20 02:08:04-05
647	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.fc6387c8b0f33702971e7e85763f40ab6133664f07f4a3db96b19377dfb782a7	2026-04-19 02:08:06-05	2026-03-20 04:59:36-05	2026-03-20 02:08:06-05
648	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.53a2f9b0ea0c80eb44acc1b45a1a6866904acfaec1bd390628b44531fa5adb47	2026-04-19 04:59:36-05	2026-03-20 14:23:02-05	2026-03-20 04:59:36-05
649	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.7812c8deb9007a4f7a43998a00249f607915645e77079303d9231a52fac8f731	2026-04-19 14:23:02-05	2026-03-20 14:23:03-05	2026-03-20 14:23:02-05
650	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.c712eab5db838f363de07771e156c0e1918aa7dfbfddb46bd8ab586752e94644	2026-04-19 14:23:03-05	2026-03-20 14:23:04-05	2026-03-20 14:23:03-05
651	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.206330751907c6bc01296fb1a71caa112564326805a5c3de60650e4850c23c17	2026-04-19 14:23:04-05	2026-03-20 14:23:05-05	2026-03-20 14:23:04-05
652	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.862f3d25fef451f7cd36bdf5608652a0b26249eaa3a945bc07c50430df51b3d5	2026-04-19 14:23:05-05	2026-03-20 14:23:06-05	2026-03-20 14:23:05-05
653	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.ed652eff5665fe51baa21fb1c3ed0a41fc8a29a49936fae0b743e81f0aa500d1	2026-04-19 14:23:06-05	2026-03-20 14:23:07-05	2026-03-20 14:23:06-05
654	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.f0d15546f76767c7855791224bc20aa6b883a69c7d33095f0c252f235e4e8bf8	2026-04-19 14:23:07-05	2026-03-20 14:23:08-05	2026-03-20 14:23:07-05
655	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.c3f1cb1ba79e46941f1c75593d323336494009c6534152d5a6c8191f8c0dd386	2026-04-19 14:23:08-05	2026-03-20 14:23:09-05	2026-03-20 14:23:08-05
656	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.f1f631dbb161b56e67ce680e60ecdc230874d89493acfe5aae63dc61fe0e9a99	2026-04-19 14:23:09-05	2026-03-20 14:23:10-05	2026-03-20 14:23:09-05
657	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.4592529d4e4838716f26978ddf79b54f472b1dda951a6f59f5d07c5ad0588eef	2026-04-19 14:23:10-05	2026-03-20 14:23:11-05	2026-03-20 14:23:10-05
658	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.379ff6f371b0fb8cd51c7af738e81f484822cba56c1665b1af0bf6d534abe689	2026-04-19 14:23:11-05	2026-03-20 14:23:12-05	2026-03-20 14:23:11-05
659	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.3f86ad78eb193863130ede7bb77e4fdba87763f0ea375974afca6f455700ee86	2026-04-19 14:23:12-05	2026-03-20 14:23:14-05	2026-03-20 14:23:12-05
660	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.0dd70c4df5b7ef5050eb050e6fa3211a02382a37dab769e36cdbb74b68c16ac5	2026-04-19 14:23:14-05	2026-03-20 14:23:15-05	2026-03-20 14:23:14-05
661	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.1a0e81de94a75873ce6f8b37ca16e9cf071738cbf69fe7b6b53087a53f7517ea	2026-04-19 14:23:15-05	2026-03-20 14:23:17-05	2026-03-20 14:23:15-05
662	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.179e3ae75174ab5e7ea17aad0ed1b258a7cd65a5f6c417f95977f65a948b3fac	2026-04-19 14:23:17-05	2026-03-20 14:23:18-05	2026-03-20 14:23:17-05
663	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.d212c81794d5299772582f5171ebf0034083c65b1d857f7a7b6caa0117b79d87	2026-04-19 14:23:18-05	2026-03-20 14:23:19-05	2026-03-20 14:23:18-05
664	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.8c7020018a74afdb06d518c042ee85c8ea22566759d87c7f1f182d041d7a5d78	2026-04-19 14:23:19-05	2026-03-20 14:23:20-05	2026-03-20 14:23:19-05
665	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.4b23bea71110b7808a02802918b6a2d7fab5dae116fcbb0aba7ead7cf30be9ba	2026-04-19 14:23:20-05	2026-03-20 14:23:21-05	2026-03-20 14:23:20-05
666	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.709116ef0ddd59fccf9419a466ffe8dc3cd0c33c900db83821ad1fec05bf969b	2026-04-19 14:23:21-05	2026-03-20 14:23:22-05	2026-03-20 14:23:21-05
667	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.00774662e84369f6a136db69c530bfa2880fc75bb9f5e6c74efab982ecdeab51	2026-04-19 14:23:22-05	2026-03-20 14:23:23-05	2026-03-20 14:23:22-05
668	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.6506836f1637143108f8ad01d873e285cf8a105c30bee85b10fb87b3da59893b	2026-04-19 14:23:23-05	2026-03-20 14:23:27-05	2026-03-20 14:23:23-05
669	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.899d4c02253264b0678eeccb8af004d3fb8a55df12ccd1af952543d1745c2bc1	2026-04-19 14:23:27-05	2026-03-20 14:53:33-05	2026-03-20 14:23:27-05
670	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.57a68c650237b3c36c3e579075b135b49182c21febdf3ac347d90ae1ae805b2c	2026-04-19 14:53:33-05	2026-03-20 14:53:34-05	2026-03-20 14:53:33-05
671	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.ee7b6dce93cabc9a5cd1b4b8060b057e54ec55aaa534907efc12216d44821058	2026-04-19 14:53:34-05	2026-03-20 14:53:45-05	2026-03-20 14:53:34-05
672	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.b4323644b95847fa18676350d36c48a0817ff20f80c87904ebf34d82cae01f91	2026-04-19 14:53:45-05	2026-03-20 14:53:46-05	2026-03-20 14:53:45-05
673	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.0d5379e54941ae54f9db748501f84add673572577dd925825427338f4616c1f1	2026-04-19 14:53:46-05	2026-03-20 14:53:57-05	2026-03-20 14:53:46-05
674	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.22f72be3044bf3a9c093ff55c3fbac15d418e0d559a1d6c4b35e08da4f68b9b8	2026-04-19 14:53:57-05	2026-03-20 14:53:58-05	2026-03-20 14:53:57-05
675	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.fef4c5052c7502748c0abdca2641b769351811600d3dcc8048c82fad80876996	2026-04-19 14:53:58-05	2026-03-20 14:54:09-05	2026-03-20 14:53:58-05
676	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.16a8b0caef7c5e4b6230fb5710db1f735af84937699ccfe1eaa25929ac561a9e	2026-04-19 14:54:09-05	2026-03-20 14:54:10-05	2026-03-20 14:54:09-05
677	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.3a8d90096bb67101685d1db7e6342c26857e5e61aece6c2484436e79f62ad752	2026-04-19 14:54:10-05	2026-03-20 14:54:21-05	2026-03-20 14:54:10-05
678	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.de8626e3e584cc44df2c212c20fae4118becfaa7d4f1f37de594111ff49d273b	2026-04-19 14:54:21-05	2026-03-20 14:54:22-05	2026-03-20 14:54:21-05
679	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.7d565ee10d95c6828831c912f9ed354218d6f817655f946239f3627663b47792	2026-04-19 14:54:22-05	2026-03-20 14:54:33-05	2026-03-20 14:54:22-05
680	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.dde12672a594e6fe3e4d69f8016d2716746dc4d3c71682b94e99132d707285da	2026-04-19 14:54:33-05	2026-03-20 14:54:34-05	2026-03-20 14:54:33-05
681	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.c55e2fb9456942b1a488526eb9c6f85e0210de356c1a188484ca419283143817	2026-04-19 14:54:34-05	2026-03-20 14:54:46-05	2026-03-20 14:54:34-05
682	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.3473ac1c4496a5e18f99400623924dbcdfa0dabcc0fa7cb46c03fdb2b61187a3	2026-04-19 14:54:46-05	2026-03-20 14:54:47-05	2026-03-20 14:54:46-05
683	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.639b78d48753401687f976fffc067597a781c028e787c56adb8a5416aac8be99	2026-04-19 14:54:47-05	2026-03-20 14:54:58-05	2026-03-20 14:54:47-05
684	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.3b8e558e11349781f42778c87439e54397ca2e59a07b23405e9abdec2c1d637e	2026-04-19 14:54:58-05	2026-03-20 14:54:59-05	2026-03-20 14:54:58-05
685	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.a28958ebf63c0b063e3a410f9fd153f5df6f85d9a5a5fcd3e13fc2c43f3195d6	2026-04-19 14:54:58-05	2026-03-20 14:55:10-05	2026-03-20 14:54:59-05
686	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.178ad0403bfdd041b4ac520dcdfe3f5d5bd60d4ae11522c66ea268d6f8b3e184	2026-04-19 14:55:10-05	2026-03-20 14:55:11-05	2026-03-20 14:55:10-05
687	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.19ab37a4f0ed0e681fc07d55bf58cfe2f328fa8dcd518aac8aad945fc4df5c78	2026-04-19 14:55:11-05	2026-03-20 14:55:21-05	2026-03-20 14:55:11-05
688	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.de860fdc39761c54e820d7b66a934320786eda63cc0ecaa93f1aea6368a7b477	2026-04-19 14:55:21-05	2026-03-20 14:55:22-05	2026-03-20 14:55:21-05
689	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.4242cbfd14b3d4fdf145bd470dca40f87a0cf42d2deff8a6f16f3e0f87fe98f1	2026-04-19 14:55:22-05	2026-03-20 14:55:33-05	2026-03-20 14:55:22-05
690	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.d2195f3aeb895c5aee8bae314f45ad82ec6628a192fb4be60b68713918572c96	2026-04-19 14:55:33-05	2026-03-20 14:55:34-05	2026-03-20 14:55:33-05
691	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.acd53f82c769ff4a500d984c390bd1c80dbd8c682036ce49773b1ad4909f051d	2026-04-19 14:55:34-05	2026-03-20 14:55:45-05	2026-03-20 14:55:34-05
692	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.858e9667227b56e5ca306d6c2f5c92aedc43c6a2d4bc62697e6f42bbe88e0d4b	2026-04-19 14:55:45-05	2026-03-20 14:55:46-05	2026-03-20 14:55:45-05
693	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.e07858eed60524a0edb67b0e576f060163c87d4ce1a29584f15fc19cb2f71415	2026-04-19 14:55:46-05	2026-03-20 14:55:58-05	2026-03-20 14:55:46-05
694	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.f7dbbadaf6601efdbc6efd9e615209510f89ca835313f812252174c979692a68	2026-04-19 14:55:58-05	2026-03-20 14:55:59-05	2026-03-20 14:55:58-05
695	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.0292ff33a77743e7bbb7dca5094ab86db26473c967e1aeb84fb195f1481b185c	2026-04-19 14:55:58-05	2026-03-20 14:56:09-05	2026-03-20 14:55:59-05
696	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.6f34f2f5b4a1981e876c1064e9a04621e81dfa60ca0ea9da8df6805fb6111864	2026-04-19 14:56:09-05	2026-03-20 14:56:10-05	2026-03-20 14:56:09-05
697	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.fe0714a45a2dfee160eb9027e794137e595dbc7670aee31d8309d2cc6c099ac9	2026-04-19 14:56:10-05	2026-03-20 14:56:21-05	2026-03-20 14:56:10-05
698	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.c6fd46bb40e19c833cbfe08358b700a540095d76c70146564a07b310d01aa7c5	2026-04-19 14:56:21-05	2026-03-20 14:56:22-05	2026-03-20 14:56:21-05
699	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.b3e6d4667f624b54e5e42366ed0e20d4782385b076267015befd765f3224ef67	2026-04-19 14:56:22-05	2026-03-20 14:56:34-05	2026-03-20 14:56:22-05
700	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.bec51a5dcf7e5177818e7d4f227d6dd5666c6f07995e8ea4a3b125d5fc810b8a	2026-04-19 14:56:34-05	2026-03-20 14:56:35-05	2026-03-20 14:56:34-05
701	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.6854e0b50adca68916691da9a79067419a33a61695c76b431aadd22e4bca1df2	2026-04-19 14:56:35-05	2026-03-20 14:56:46-05	2026-03-20 14:56:35-05
702	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.fb222e34052f30ce46bb8bf4edec4d2e397f4b0cd434f64fdae22583f879957b	2026-04-19 14:56:46-05	2026-03-20 14:56:47-05	2026-03-20 14:56:46-05
703	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.141160e92287e18fb845bcb99f9f2aa39b9012e99f8f8eca274f9e142a63c2c9	2026-04-19 14:56:47-05	2026-03-20 14:56:58-05	2026-03-20 14:56:47-05
704	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.aaf28ca05493e97f08517cd326c4cdd9e69037372afdcd5a884560c9eed96558	2026-04-19 14:56:58-05	2026-03-20 14:56:59-05	2026-03-20 14:56:58-05
705	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.1d4254272a8971e929cb9fb91ad500b222051b4bc8fadeb8f14f2d3eecd2e9d4	2026-04-19 14:56:59-05	2026-03-20 14:57:10-05	2026-03-20 14:56:59-05
706	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.12276e00a4e692d65fa386a988605de7d15f8a26711e81d0c46f8ddc07d9e50f	2026-04-19 14:57:10-05	2026-03-20 14:57:11-05	2026-03-20 14:57:10-05
707	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.eba1f40ab6602828be70ace24dd88cef60bea463049fc8a6ad016b4df944bcc9	2026-04-19 14:57:11-05	2026-03-20 14:57:22-05	2026-03-20 14:57:11-05
708	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.93bc2f420db8936f478cb9297916843aaacd65bbb1e0742ac2a15a0991dedc97	2026-04-19 14:57:22-05	2026-03-20 14:57:23-05	2026-03-20 14:57:22-05
709	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.c183389dde9b35a04b2e67e0087a167d3220a6052ac2762912b3722c65ceb4e9	2026-04-19 14:57:23-05	2026-03-20 14:57:39-05	2026-03-20 14:57:23-05
710	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.4ee9cec92dab12a0ecaf973ecec2633b557ecfa1dd19cfa0d65b7ef6d9fee84e	2026-04-19 14:57:39-05	2026-03-20 14:57:40-05	2026-03-20 14:57:39-05
711	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.41ded06db8b1898303fbab0dab085f9a10a60cc9865b9f857c8b442c85699fca	2026-04-19 14:57:40-05	2026-03-20 14:57:46-05	2026-03-20 14:57:40-05
712	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.5c5062050cbc4fca95e92db068121e44eb9eddb0d01e2a8626761a1a0805f1ea	2026-04-19 14:57:46-05	2026-03-20 14:57:46-05	2026-03-20 14:57:46-05
713	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.8111284b2e82bcd77d391f1d3358d6922f5796706239d2646bc22e3b19f2ddfb	2026-04-19 14:57:46-05	2026-03-20 14:57:58-05	2026-03-20 14:57:46-05
714	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.625c86634ea3f64e656af5393b83d92ec0ff70a3fd89cf615a963c8278daae93	2026-04-19 14:57:58-05	2026-03-20 14:57:59-05	2026-03-20 14:57:58-05
715	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.35a15e52f810cf682ca543f079ab96195b23c60d1bafdb5d09e9c6ad8a0052f4	2026-04-19 14:57:59-05	2026-03-20 14:58:10-05	2026-03-20 14:57:59-05
716	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.4c52222e824d7572fa8c083e0d9a309988791de3428989a8bfecf4b52b08ac60	2026-04-19 14:58:10-05	2026-03-20 14:58:11-05	2026-03-20 14:58:10-05
717	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.3287db90e40fb3e96b88ef8a0efd2409f1e7eb4f1f5401713fd6cb59323a7764	2026-04-19 14:58:11-05	2026-03-20 14:58:22-05	2026-03-20 14:58:11-05
718	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.b4cd712a4e6213deb1c611053d59b77fd515b20809b31f118a6eb92e965642db	2026-04-19 14:58:22-05	2026-03-20 14:58:23-05	2026-03-20 14:58:22-05
719	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.2c51a6a08290714324f4f1904b257ec558df2d3b80b19efea60cfb6cf1421139	2026-04-19 14:58:23-05	2026-03-20 14:58:34-05	2026-03-20 14:58:23-05
720	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.bf4aab70a615b6ccaa647d57cad4873dab9beeed7654cde788babcd1fc721edf	2026-04-19 14:58:34-05	2026-03-20 14:58:35-05	2026-03-20 14:58:34-05
721	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.fd2dc64543f6a77f56653b530df855e63e25bfdfc7df0b750a6a204aec22917c	2026-04-19 14:58:35-05	2026-03-20 14:59:35-05	2026-03-20 14:58:35-05
722	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.ee690eb36640a51ad477bb83030628a425ec962b5ddc65b5fd17182ee527acc6	2026-04-19 14:59:35-05	2026-03-20 14:59:35-05	2026-03-20 14:59:35-05
723	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.1129770c8bfdbf86dc3fee127a5435964c12c4ebd9052881f686c4a127217ed2	2026-04-19 14:59:35-05	2026-03-20 15:00:16-05	2026-03-20 14:59:35-05
724	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.e924393698d222f23add3093492ec3b83c142117b9d2b7686448a143b02db58d	2026-04-19 15:00:16-05	2026-03-20 15:00:17-05	2026-03-20 15:00:16-05
725	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.ff9f8a34ee01ecb56ad49a66100d339d96ffd862c6777db7bc0b224b4f6126dc	2026-04-19 15:00:17-05	2026-03-20 15:33:35-05	2026-03-20 15:00:17-05
726	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.37fcf967f74d7379441b399705ee2f49da64d7be363f88778f1a041fdadd7749	2026-04-19 15:33:35-05	2026-03-20 15:33:36-05	2026-03-20 15:33:35-05
727	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.2315b1429d344582f05686e1eee21e7e215a71d492d87ec7d5be974fbea71f1f	2026-04-19 15:33:36-05	2026-03-20 15:33:38-05	2026-03-20 15:33:36-05
728	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.9b63f7b835555645eada58779a9e290b5ba841f91917dc881b9a50d50e72d10f	2026-04-19 15:33:38-05	2026-03-20 15:40:10-05	2026-03-20 15:33:38-05
730	2	cf438b7f74983d1ccdd9cd3c8da9df040f09ab6c.684ac86f50ee7bf650dec94de32616562a32431934f5ad722f9a815cf8684443	2026-04-19 16:15:10-05	\N	2026-03-20 16:15:10-05
731	3	bd518607f648ed66001079a92e767bb30c3efef1.de219b051afc0741e6b461b28dd38ce655c03ed3807f8bbbe1774152755bee74	2026-04-19 16:16:33-05	\N	2026-03-20 16:16:33-05
729	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.05e90ab36354030b775ea2517f7409f2e95cf1e659d7712d541a55bb8c671bd1	2026-04-19 15:40:10-05	2026-03-20 16:17:10-05	2026-03-20 15:40:10-05
732	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.4a5b246b47b77ec33e9a705e253786887e2539c3894bef505074bfa5cad705d4	2026-04-19 16:17:10-05	2026-03-20 16:17:11-05	2026-03-20 16:17:10-05
733	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.25e181c80c2f0865506e9f5613ff014c97805f25d57bc65907f02323d3371cc9	2026-04-19 16:17:11-05	2026-03-20 16:17:13-05	2026-03-20 16:17:11-05
734	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.0f39e7856a34796fe682fce304efce5555ddf8b1d48c883536a0c5cc12939691	2026-04-19 16:17:13-05	2026-03-20 16:17:16-05	2026-03-20 16:17:13-05
735	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.15639a529cd811137c832756c6ad1bfb91bd2e2029d4510a88ba4b524f4c3e30	2026-04-19 16:17:16-05	2026-03-20 16:17:17-05	2026-03-20 16:17:16-05
736	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.0bc4d8014ffb57b17e7f265a2ff35f661206a566802280ddcff01ea5356e4275	2026-04-19 16:17:17-05	2026-03-20 16:17:19-05	2026-03-20 16:17:17-05
737	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.06a281e466b5dd43ed5d0f437eb575070d9982cffa28ab859b69e66782ea0827	2026-04-19 16:17:19-05	2026-03-20 16:17:21-05	2026-03-20 16:17:19-05
738	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.6ae4a4ea006934997f0651c9773c4db0b4d452b1bc5f64d470b2046daea14cf8	2026-04-19 16:17:21-05	2026-03-20 16:17:42-05	2026-03-20 16:17:21-05
740	2	8ab09b2568b7637b9530d15a4e72103b0c708c61.309d7d63bce61bf868e9ebb22c0cde5b9faa3638555e62f5c8200dd7e551b882	2026-04-19 16:26:23-05	\N	2026-03-20 16:26:23-05
741	2	45fd89725c43934cd18dbc6c62b4d05ccd10adc1.5d95e5ecbb9d1c942a64c4eb2b5fb09d28d3b31db7b549a45a603334d9e31543	2026-04-19 16:26:42-05	\N	2026-03-20 16:26:42-05
742	3	1ad1de207e9ae657878d9c820a6dd749a8fe31e6.6640d174053346f72635e32141605da2c2571e245f46b330c1d0e62f392b7b66	2026-04-19 16:28:07-05	\N	2026-03-20 16:28:07-05
743	3	21d939c2078f0dd9b46698d9f47b8af44b88e40c.b0d4292fa024b76f89fcbcddccdefdca7d7e33d6eee3aaeaf093568013a09c3d	2026-04-19 16:28:09-05	\N	2026-03-20 16:28:09-05
744	2	a167eb650245e79f7162714ef3b6497d6aaa3c99.0d6be35fb10502571b1b09d53f9234ac302411887cc18530588c6a8f5a059e41	2026-04-19 16:28:11-05	\N	2026-03-20 16:28:11-05
745	2	f2a1e38f63f8bf4803b8660021fe850a2f19498e.a8ba4d41ad7a6b7900bc91f21adfeada9f06de4d4ea38ca3b77d1f1d3aa52dfd	2026-04-19 16:28:18-05	\N	2026-03-20 16:28:18-05
739	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.3da04346c6a4ec6aaf5b997c838aa3954aee57934ec15f8588b181e158e02835	2026-04-19 16:17:42-05	2026-03-20 16:34:35-05	2026-03-20 16:17:42-05
747	3	e62f2cb6d62e5d0908c37f0f4dfd292fbd4406ff.a76a39c73f92960ba0b3757a6a0cfe4611169cf456be1728b7ddd34a4fc4f37d	2026-04-19 16:37:09-05	\N	2026-03-20 16:37:09-05
746	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.7ce11c104c5d72c355ac550df9a77383aa61be8c86d688bd9d7c0efdaf52139b	2026-04-19 16:34:37-05	2026-03-20 16:42:09-05	2026-03-20 16:34:37-05
748	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.4b12424e13a96b033e41ee78c3517ea07f1c6b7f7cef2cb92e5c98780f00511d	2026-04-19 16:53:16-05	2026-03-20 16:56:07-05	2026-03-20 16:53:16-05
749	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.c9047e3a0237e866a2722530bdef9f6927f44a0e6ab7f6cd6c523ebcd5ac5837	2026-04-19 16:56:12-05	2026-03-20 17:06:25-05	2026-03-20 16:56:12-05
750	1	4dbea6fc7ee4f506861bc3c58f8cb8a2699b93f2.5388ea4d7109177691c4c1dd1e4dd863f51cbb6c242484dbccfa5e841d194a3f	2026-04-19 17:07:31-05	\N	2026-03-20 17:07:31-05
751	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.2cf31fc85049d8ee0e2d079087176f3350b1cd7d67b7e2d604d48b656a1d09fb	2026-04-19 17:07:42-05	2026-03-20 17:07:51-05	2026-03-20 17:07:42-05
752	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.e661227e303d07ecb6acdc66f06b89be7986b19d11058442246467c08bc9db3d	2026-04-19 17:07:59-05	2026-03-20 17:09:43-05	2026-03-20 17:07:59-05
753	2	7e714d6d8bc201e47f5042559c1cc6214446e6b8.3534590ace3e49049093c9284dcc400bb3e1d8057ecddffa7db3f580d19bbc4e	2026-04-19 17:09:59-05	2026-03-20 17:42:33-05	2026-03-20 17:09:59-05
754	2	7e714d6d8bc201e47f5042559c1cc6214446e6b8.07a8da66b027b7550228d27e2c7cc5c9363a98ebb6302cac8b205562f225fdc8	2026-04-19 17:42:33-05	2026-03-20 17:42:34-05	2026-03-20 17:42:33-05
755	2	7e714d6d8bc201e47f5042559c1cc6214446e6b8.4a339dea196968bd95bf380aa61354964e11f6f1aa5b2fa93d57473ac3855bc0	2026-04-19 17:42:34-05	2026-03-20 17:42:35-05	2026-03-20 17:42:34-05
756	2	7e714d6d8bc201e47f5042559c1cc6214446e6b8.57cd10c5f62c0a72858c160ce16567e28ef2e7723a2bcfac3156e49e101a6524	2026-04-19 17:42:35-05	2026-03-20 17:42:36-05	2026-03-20 17:42:35-05
757	2	7e714d6d8bc201e47f5042559c1cc6214446e6b8.69a632bb3f46b89ca1ac37cb69ce9bba5008bbbcba574520a886c9ff0dbda7b8	2026-04-19 17:42:36-05	2026-03-20 17:42:37-05	2026-03-20 17:42:36-05
758	2	7e714d6d8bc201e47f5042559c1cc6214446e6b8.b336590f86eecdb558fe57e2d1fafd2c8776affbc6422044a48e0b2b1cb5cc60	2026-04-19 17:42:37-05	2026-03-20 17:42:38-05	2026-03-20 17:42:37-05
759	2	7e714d6d8bc201e47f5042559c1cc6214446e6b8.c86033ecd76baf9affa1fd2c7745648b0f152255d9b471d4a890ab8b18079327	2026-04-19 17:42:38-05	2026-03-20 17:42:39-05	2026-03-20 17:42:38-05
761	3	7e714d6d8bc201e47f5042559c1cc6214446e6b8.9d4fe8f7381e7523818bd497540b599d6ff8b5cb4685bce37ba72884095e00a1	2026-04-19 17:42:52-05	2026-03-20 17:54:08-05	2026-03-20 17:42:52-05
762	3	7e714d6d8bc201e47f5042559c1cc6214446e6b8.cf65607ebfaa8ef88b6c0feb098ebd18a6a43755085273688e54671b36710cdf	2026-04-19 17:54:21-05	2026-03-20 18:03:13-05	2026-03-20 17:54:21-05
763	3	7e714d6d8bc201e47f5042559c1cc6214446e6b8.501efeb1e9d22cfcea71c5b4624419f892dd10db45b31ad33dd817bc8c89238a	2026-04-19 18:03:13-05	2026-03-20 18:03:14-05	2026-03-20 18:03:13-05
764	3	7e714d6d8bc201e47f5042559c1cc6214446e6b8.bbec2aceff603c6e6a82f31028fe3591d10311ad3ece83580053ecba53fb94a6	2026-04-19 18:03:14-05	2026-03-20 18:04:24-05	2026-03-20 18:03:14-05
765	3	7e714d6d8bc201e47f5042559c1cc6214446e6b8.c3f55cd81e38fdf5241dfbcbc0bdcfd018301233633acfee2179a3b65f8b5dcd	2026-04-19 18:04:24-05	2026-03-20 18:35:04-05	2026-03-20 18:04:24-05
766	3	7e714d6d8bc201e47f5042559c1cc6214446e6b8.a08856965a7313f9265245e6a11be3738e021ae44a2f6d400c3dc310e3aebee2	2026-04-19 18:35:04-05	2026-03-20 18:35:51-05	2026-03-20 18:35:04-05
767	3	7e714d6d8bc201e47f5042559c1cc6214446e6b8.904ef170ab7ab17ac2d9c7ac96901261e1a9e861f9ef3c68844b575491eaaaa9	2026-04-19 18:37:47-05	2026-03-20 18:37:51-05	2026-03-20 18:37:47-05
768	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.460539c0f6e734d75d0682868f15537db982a4a3f99ad6443c6b54bc8a36464c	2026-04-19 18:37:54-05	2026-03-20 18:56:03-05	2026-03-20 18:37:54-05
760	2	7e714d6d8bc201e47f5042559c1cc6214446e6b8.b7b5d934d3add8b7fd4aa69b27eecc8723b5497a958554ca86087b344b4fc522	2026-04-19 17:42:39-05	2026-03-20 18:56:07-05	2026-03-20 17:42:39-05
769	2	7e714d6d8bc201e47f5042559c1cc6214446e6b8.123c07dce36eaa4a7bffbe881215455ffd7fd1154a75d29986e6d18b0ae02367	2026-04-19 18:56:07-05	2026-03-20 18:57:08-05	2026-03-20 18:56:07-05
770	3	7e714d6d8bc201e47f5042559c1cc6214446e6b8.cc52619bb797bbdeef2977e5d9652bae99db1e9a6725d744ea02a4b859f76fbb	2026-04-19 18:57:16-05	2026-03-20 19:04:26-05	2026-03-20 18:57:16-05
771	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.ea8e33f4d6f28fb7f143e60e6a156d6877633ad2619771a6f04fa70c46c6b53e	2026-04-19 19:04:32-05	2026-03-20 19:05:56-05	2026-03-20 19:04:32-05
772	2	7e714d6d8bc201e47f5042559c1cc6214446e6b8.313806e8e0938baae74edfb029c1afdcaf98a0396e29f20abdc40ef20a366181	2026-04-19 19:06:07-05	2026-03-20 19:06:29-05	2026-03-20 19:06:07-05
773	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.fdff629231a2dc58f1dd11e3339dd5210a3ad8a7b76c21d68f003162187837a7	2026-04-19 19:06:33-05	2026-03-20 19:09:37-05	2026-03-20 19:06:33-05
774	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.5d5d86256bfd5103de08747a680fa9e61b14de37a95355086c71eb6b0ad4d5c1	2026-04-19 19:09:37-05	2026-03-20 19:12:59-05	2026-03-20 19:09:37-05
775	3	7e714d6d8bc201e47f5042559c1cc6214446e6b8.a6f00de1310f3abfcb8154824dbf176bbe9c086a9f9a83b862219cba11db006e	2026-04-19 19:13:07-05	2026-03-20 19:18:12-05	2026-03-20 19:13:07-05
776	3	7e714d6d8bc201e47f5042559c1cc6214446e6b8.9f9691bc557de98c613d6b794469ba80c41be268941b8ba95c86752bf269d82f	2026-04-19 19:18:12-05	2026-03-20 19:18:31-05	2026-03-20 19:18:12-05
777	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.b48c31318ee14b4519eebf075de4284088da73bf2912e0b18f86058e5cb9b802	2026-04-19 19:18:35-05	2026-03-20 19:21:59-05	2026-03-20 19:18:35-05
778	2	7e714d6d8bc201e47f5042559c1cc6214446e6b8.27e1abeb3d733fb6d950008231dab60cf1f450c5b1f3fe61ea60b6323c727975	2026-04-19 19:22:10-05	2026-03-20 19:22:53-05	2026-03-20 19:22:10-05
779	3	7e714d6d8bc201e47f5042559c1cc6214446e6b8.6b0a5492716dcdbbdb0acf8871af143a746088a84c99783c767e1083ed941229	2026-04-19 19:23:00-05	2026-03-20 21:36:49-05	2026-03-20 19:23:00-05
780	3	7e714d6d8bc201e47f5042559c1cc6214446e6b8.c61ad2b7e3a80f594a97e56fff4bccc04c1263d7ffed25bc894ea46fdee438cf	2026-04-19 21:36:49-05	2026-03-20 21:36:50-05	2026-03-20 21:36:49-05
782	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.ecf0b4a6839e3aa368a819fec299512790a0bdcd32be0c3d46152982ed801e3d	2026-04-19 21:38:04-05	2026-03-20 22:17:25-05	2026-03-20 21:38:04-05
783	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.d8914154f7a84db8d0fee0a4b41834fd3f42e2411f518ca8ed44778112f09a1c	2026-04-19 22:17:25-05	2026-03-20 22:17:26-05	2026-03-20 22:17:25-05
784	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.7874673186568825943f301e0e617c414473047375ff8df7486b1fa396353124	2026-04-19 22:17:26-05	2026-03-20 22:17:34-05	2026-03-20 22:17:26-05
785	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.709f986634307ed18cab50af2407f4f2aa32a970977176286b10d21dc40cfc2d	2026-04-19 22:17:34-05	2026-03-21 13:31:42-05	2026-03-20 22:17:34-05
786	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.c40c225dc90805ac2df94521db9f9661e6fb86824f4e9e893134190fbaf9a25a	2026-04-20 13:31:42-05	2026-03-21 13:31:44-05	2026-03-21 13:31:42-05
787	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.b6138ca1b561062d48549e2a5624d828be2d9cb938f07fb6ec7252de2315f691	2026-04-20 13:31:44-05	2026-03-21 13:31:45-05	2026-03-21 13:31:44-05
788	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.728495d69ee78b984db5cf6c5315e805edcc50f6f56e30ec68d399bf96be0449	2026-04-20 13:31:45-05	2026-03-21 13:31:46-05	2026-03-21 13:31:45-05
789	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.a27c763b4a25a4af0f4165a538c2eef3634e834dc11bba5047e5d27604b4c062	2026-04-20 13:31:46-05	2026-03-21 13:31:47-05	2026-03-21 13:31:46-05
790	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.10eaa8c56df46f63b8174474f5f1e59783173f1b928a1bc8686a9887884e93a4	2026-04-20 13:31:47-05	2026-03-21 13:31:48-05	2026-03-21 13:31:47-05
791	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.fb3a63d3100db3e8ed7a58f1e4a911dfa1206881bbde0e7393d79b6a82bb28fa	2026-04-20 13:31:48-05	2026-03-21 13:31:49-05	2026-03-21 13:31:48-05
792	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.7be694b87e9ce59a3b266773007dc84176c8787c7f9a18cbccaef3f1ed829599	2026-04-20 13:31:49-05	2026-03-21 13:32:05-05	2026-03-21 13:31:49-05
793	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.acc052e3b0c8ed50477461d0f0d4f8b687b34faf9be63b726adc18500ec7d9e7	2026-04-20 13:32:05-05	2026-03-21 13:45:36-05	2026-03-21 13:32:05-05
794	2	7e714d6d8bc201e47f5042559c1cc6214446e6b8.e63c4f79c621be6447245fc0524a4673a6eb929d6e03119c27d3066ff1e8e5b8	2026-04-20 13:46:05-05	2026-03-21 13:46:43-05	2026-03-21 13:46:05-05
781	3	7e714d6d8bc201e47f5042559c1cc6214446e6b8.bcda9804fdcfc818798910e88596d1889e413e006be5cb2aa1d020286368673c	2026-04-19 21:36:50-05	2026-03-21 13:46:55-05	2026-03-20 21:36:50-05
796	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.4e0fe2bd88c9ce5c6fa3031fd9c97ac105d4cb4dc062810322accbace49c8c4b	2026-04-20 16:15:38-05	2026-03-21 16:57:46-05	2026-03-21 16:15:38-05
797	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.5877a7d6809aed849c4294297abb419fd4cdc014d76d320105eae460463315ad	2026-04-20 16:57:46-05	2026-03-21 17:38:27-05	2026-03-21 16:57:46-05
798	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.09600ade98f4bc7f67e06f0d083dc7f37af5f3afb60425df2d41535698f8fb48	2026-04-20 17:38:27-05	2026-03-21 20:06:00-05	2026-03-21 17:38:27-05
799	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.5cd45159da0ff0a82bff62d578846312e533a076392e13d510dcbb722523dc14	2026-04-20 20:06:00-05	2026-03-21 20:07:14-05	2026-03-21 20:06:00-05
795	3	7e714d6d8bc201e47f5042559c1cc6214446e6b8.33b77923183d257791284506790fadcff4cf71b92b835ef3d972705e0ece5fd9	2026-04-20 13:46:55-05	2026-03-21 20:07:26-05	2026-03-21 13:46:55-05
800	3	7e714d6d8bc201e47f5042559c1cc6214446e6b8.b8b79d2d9cab397f057ce9a042b1c0fc214dbca4465b66f31aa9c1acfc8f22d2	2026-04-20 20:07:26-05	2026-03-21 20:13:50-05	2026-03-21 20:07:26-05
801	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.5fa1c2e7e982850e6e07dae8bc0c28b87b5a83008e0685881ffbcc7771fc30ce	2026-04-20 20:13:54-05	2026-03-21 20:15:57-05	2026-03-21 20:13:54-05
802	2	7e714d6d8bc201e47f5042559c1cc6214446e6b8.ff57c1d949d2798e02d8ff1580acc6ab81c6e6741704f411df3515c4f5871c47	2026-04-20 20:16:09-05	2026-03-21 20:16:31-05	2026-03-21 20:16:09-05
803	3	7e714d6d8bc201e47f5042559c1cc6214446e6b8.04e5b0f23a1d2e2abec3109b5b6ed3a69c046d95fdf797fa719383c0225cfbc1	2026-04-20 20:16:40-05	2026-03-21 20:17:35-05	2026-03-21 20:16:40-05
804	2	7e714d6d8bc201e47f5042559c1cc6214446e6b8.f1b3a2a5931f4e8bce3c330373056606b7005e3f831f34aac0a4e3651f0f93a9	2026-04-20 20:17:46-05	2026-03-21 20:18:26-05	2026-03-21 20:17:46-05
805	3	7e714d6d8bc201e47f5042559c1cc6214446e6b8.ce03486c3f755e210e590b285f25c45cf46fd27b8e1e309edd6ba27e859b7903	2026-04-20 20:18:59-05	2026-03-26 03:37:03-05	2026-03-21 20:18:59-05
806	3	7e714d6d8bc201e47f5042559c1cc6214446e6b8.b48862cc2b7267d0f1a66c3d2c6bad1a934788fa42b8703dca0bb19160ca4967	2026-04-25 03:37:03-05	2026-03-26 03:37:04-05	2026-03-26 03:37:03-05
807	3	7e714d6d8bc201e47f5042559c1cc6214446e6b8.9e09dd9bd2155dd9870670bb45c2b424a2c7de283c3934477776945f91012a40	2026-04-25 03:37:04-05	2026-03-26 03:37:05-05	2026-03-26 03:37:04-05
808	3	7e714d6d8bc201e47f5042559c1cc6214446e6b8.c4107c6edebec33b5fdf45fcecb811aa78e12d189f92c2b0fb224c0275a60fa6	2026-04-25 03:37:05-05	2026-03-26 03:37:06-05	2026-03-26 03:37:05-05
809	3	7e714d6d8bc201e47f5042559c1cc6214446e6b8.230f0743be1d16bcb15890944553c48ff09b69c74cd978016b5ccdb3f3e68210	2026-04-25 03:37:06-05	2026-03-26 03:37:07-05	2026-03-26 03:37:06-05
810	3	7e714d6d8bc201e47f5042559c1cc6214446e6b8.4eb140e1420df2bb3222526b429234dd23ba00cc0e4931b70158394b2ad0b5a9	2026-04-25 03:37:07-05	2026-03-26 03:37:08-05	2026-03-26 03:37:07-05
811	3	7e714d6d8bc201e47f5042559c1cc6214446e6b8.4113864ab20b29d2fb6b6a814a47d53c901428aa0e25f8ee1e176782f939bd1a	2026-04-25 03:37:08-05	2026-03-26 03:37:10-05	2026-03-26 03:37:08-05
813	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.dd8783b72161571309d4f14c9c48db2fdb9913b2e144ab1a526aae8b82e21b5e	2026-04-25 03:37:26-05	2026-03-26 04:09:01-05	2026-03-26 03:37:26-05
814	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.9a196d2dc9edc130c3ca78efd7f0584d4055a7856e9a1c15c54eb861dd2c5b20	2026-04-25 04:09:01-05	2026-03-26 04:46:02-05	2026-03-26 04:09:01-05
815	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.83dd20a16db6d768c9da56e76d896c751f518e8a976b8a641c7b0205e9dd420c	2026-04-25 04:46:02-05	2026-03-26 04:46:03-05	2026-03-26 04:46:02-05
816	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.c0b922df7544e4774de75b3bbb7fd3da438a6271eed12785a5375dbd16a8c9cf	2026-04-25 04:46:03-05	2026-03-26 04:46:10-05	2026-03-26 04:46:03-05
817	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.4a88f282cfb9ebb839ad14a8179da7db2d910157e78147270d81f5fb41c2c7ef	2026-04-25 04:46:10-05	2026-03-26 04:47:15-05	2026-03-26 04:46:10-05
818	2	7e714d6d8bc201e47f5042559c1cc6214446e6b8.ae7ed4121206d40bdf6cbcbe44432c30581382062477ec20d6e40c01d98346ac	2026-04-25 04:47:53-05	2026-03-26 04:47:55-05	2026-03-26 04:47:53-05
812	3	7e714d6d8bc201e47f5042559c1cc6214446e6b8.5a6cc1cc48218989209cdc9afa7823a7cc7ae81aa94610be33b459959dd15236	2026-04-25 03:37:10-05	2026-03-26 04:48:02-05	2026-03-26 03:37:10-05
819	3	7e714d6d8bc201e47f5042559c1cc6214446e6b8.f10cfc27556b9b8209c29e798f4c9b008a7ba9ff9efe8948920d7e4eb8dffdaa	2026-04-25 04:48:02-05	2026-03-26 04:49:36-05	2026-03-26 04:48:02-05
820	2	7e714d6d8bc201e47f5042559c1cc6214446e6b8.6aa4cd0fc7c2d20b207a38f06a7da4f72d17183241c74b75947d73dbfedf5de4	2026-04-25 04:49:51-05	2026-03-26 04:50:37-05	2026-03-26 04:49:51-05
821	3	7e714d6d8bc201e47f5042559c1cc6214446e6b8.68d5f84bd0b48afae3365722e1d495ac006dce42bb77ae27e6eddde51ecc58ad	2026-04-25 04:50:45-05	2026-03-26 05:12:06-05	2026-03-26 04:50:45-05
822	3	7e714d6d8bc201e47f5042559c1cc6214446e6b8.6e42a2d06cded6991f1be5c6622aec1e6d3c609830d4d55c05cd2a31fa2b058b	2026-04-25 05:12:16-05	2026-03-26 05:24:47-05	2026-03-26 05:12:16-05
823	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.a3f33d6229d5b2922d48d568f0d0129b92314a5dbd1387d31dd7fd31dd21088d	2026-04-25 05:24:52-05	2026-03-26 05:30:42-05	2026-03-26 05:24:52-05
824	3	7e714d6d8bc201e47f5042559c1cc6214446e6b8.d4c9b0dafe92b772021febe57e1f77b7fdf3eb822da52880b0a4053360feb1a2	2026-04-25 05:30:49-05	2026-03-26 05:36:10-05	2026-03-26 05:30:49-05
825	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.cd4fbf1477c84f7a9db0489601b67c68bfd8172d69f613385773ceb1b98928fb	2026-04-25 05:36:24-05	2026-03-26 06:06:45-05	2026-03-26 05:36:24-05
826	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.96a7023f231989a11ea1b4a8cc6e7d7e4245bd50565eb64146856cc4454093ac	2026-04-25 06:06:45-05	2026-03-26 06:06:46-05	2026-03-26 06:06:45-05
827	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.930db63ea8751a0aca9c0ce25f51d94f12e6b237437db365203394731351b549	2026-04-25 06:06:46-05	2026-03-26 06:06:47-05	2026-03-26 06:06:46-05
828	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.3d36c7faa2a11d076199172d8760e4019d095bb19010cd201521dca8c158eb80	2026-04-25 06:06:47-05	2026-03-26 06:06:48-05	2026-03-26 06:06:47-05
829	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.a590970c104c08627ecbfc1789b081b2bd5913389d5c8b396532504e833b03fb	2026-04-25 06:06:48-05	2026-03-26 06:06:49-05	2026-03-26 06:06:48-05
830	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.e3066cfc7fa57e88e1ee4225d176ae4a5629220781e4e10e5923da9977b402de	2026-04-25 06:06:49-05	2026-03-26 06:06:54-05	2026-03-26 06:06:49-05
831	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.d9588f307941c3c404da155de61a30c3d844c1239574f6ff6ee7bc4adb35e9a5	2026-04-25 06:06:54-05	2026-03-26 06:37:09-05	2026-03-26 06:06:54-05
832	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.d4bba2eda0890dd5a9f469827ed3526db719554a9876a4a97cf9a9df08607fd0	2026-04-25 06:37:09-05	2026-03-27 01:41:57-05	2026-03-26 06:37:09-05
833	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.4adb55bc57ccb685a5843fa55614a1708fe431c620bb3eafe1dffc586e2fa6b3	2026-04-26 01:41:57-05	2026-03-27 01:41:58-05	2026-03-27 01:41:57-05
834	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.0b9ec530ccbaf53ea96a98eb608d9c9b8505dfff046eb3a2eadc776eed454dc3	2026-04-26 01:41:58-05	2026-03-27 01:42:00-05	2026-03-27 01:41:58-05
835	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.6d3d246bf9c6603391153042e9bc121f6bf44250b0efee5b357ff4609c00383e	2026-04-26 01:42:00-05	2026-03-27 01:42:01-05	2026-03-27 01:42:00-05
836	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.f8a1d3ca4ded971d1d7483747bfb3a399d1cb2116b825349c4074387690c9dc7	2026-04-26 01:42:01-05	2026-03-27 01:42:02-05	2026-03-27 01:42:01-05
837	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.57bb3b1532fdc9430e134d45d9311c0c53098283bdef921883462e4b70566caa	2026-04-26 01:42:02-05	2026-03-27 01:42:03-05	2026-03-27 01:42:02-05
838	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.e9b4bb2f58fee47a5f72f5cfe21c00088868a9a2c0e8f43dbb7db3cfa6374617	2026-04-26 01:42:03-05	2026-03-27 01:42:05-05	2026-03-27 01:42:03-05
839	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.630a502086c0760be0183f217880f0f44fa77fa3903c99a165eefe0ed534aa4f	2026-04-26 01:42:05-05	2026-03-27 01:42:11-05	2026-03-27 01:42:05-05
840	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.6d612db71a124222caaee480fce3d7466e442ae7e22d27205462b9f439ad670c	2026-04-26 01:42:11-05	2026-03-27 01:42:23-05	2026-03-27 01:42:11-05
841	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.4215ae7a1b5ed5bd7200307e70c6fa88664d00b16ff8bff56764a38f65226652	2026-04-26 01:42:23-05	2026-03-27 02:16:55-05	2026-03-27 01:42:23-05
842	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.ddd28cf939bb5f652d16fd59a8f53958f123fb78488ec88c664652cc1e3757ac	2026-04-26 02:16:55-05	2026-03-27 02:24:42-05	2026-03-27 02:16:55-05
843	3	7e714d6d8bc201e47f5042559c1cc6214446e6b8.8e1f01f33ebd3cf4a97a9082ea8946a64d6062dd23e7f3d8f68a48dd9a8473d2	2026-04-26 02:24:54-05	2026-03-27 02:26:45-05	2026-03-27 02:24:54-05
844	3	7e714d6d8bc201e47f5042559c1cc6214446e6b8.2373f5884977edeb46435c1d81fc313e202f69ee9f78218566dc06948b7cfa90	2026-04-26 02:26:51-05	2026-03-27 02:27:39-05	2026-03-27 02:26:51-05
845	2	7e714d6d8bc201e47f5042559c1cc6214446e6b8.c2283b62c8c0d2273284a804486ec6f71212c57f0ba3be3ed883d8cd00421007	2026-04-26 02:27:45-05	2026-03-27 02:58:02-05	2026-03-27 02:27:45-05
846	2	7e714d6d8bc201e47f5042559c1cc6214446e6b8.548c38aead9faa7a281bfafe3eecaf8157f5b802b80c93424ee205c92499679f	2026-04-26 02:58:02-05	2026-03-27 02:58:03-05	2026-03-27 02:58:02-05
848	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.22b50950066efa580d65bd777b227a99de736fa4a79c94ee47ffb26afb3064f9	2026-04-26 02:58:06-05	2026-03-27 03:32:53-05	2026-03-27 02:58:06-05
849	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.98d7d938d2c86a4370f7532b17135ad28b673d11fe1f771f60652a4a98633914	2026-04-26 03:32:53-05	2026-03-27 03:33:43-05	2026-03-27 03:32:53-05
847	2	7e714d6d8bc201e47f5042559c1cc6214446e6b8.be67102918999606e24dc26dae113967556faa976f43a15cace2cfe6945ef301	2026-04-26 02:58:03-05	2026-03-27 03:33:54-05	2026-03-27 02:58:03-05
850	2	7e714d6d8bc201e47f5042559c1cc6214446e6b8.a730d721a7b113a2a30a3f59891e81bf77f2253acff7ffb8e4750cff0fe56597	2026-04-26 03:33:54-05	2026-03-27 03:40:21-05	2026-03-27 03:33:54-05
851	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.35ef23394f6e302eb2016a889602c7eef1be1d219091984bdd5a31b61a8b37c5	2026-04-26 03:40:24-05	2026-03-27 04:11:10-05	2026-03-27 03:40:24-05
852	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.d6e4a00d3ccf031baacffb8c50214cd4ad938574d21fc148270d07dea4c23272	2026-04-26 04:11:10-05	2026-03-27 04:11:12-05	2026-03-27 04:11:10-05
853	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.830fdbbe00415f5965600b32a45796fb4af45e23c175c29f73c20c81038aa68b	2026-04-26 04:11:12-05	2026-03-27 04:11:13-05	2026-03-27 04:11:12-05
854	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.a3c9aae9681b82bf1e93b0ff5e1ff7ea987c70f72782d091082bcab6bb9de325	2026-04-26 04:11:13-05	2026-03-27 04:11:15-05	2026-03-27 04:11:13-05
855	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.dc28093dbfab5778c200dbb848300b4961d3fb54e9c19d5eb63c1679d784c53e	2026-04-26 04:11:15-05	2026-03-27 04:11:20-05	2026-03-27 04:11:15-05
856	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.a569b29aac4c5a293288529abc431e29f9b1ffb681f8bd51e1857a9deccd00fb	2026-04-26 04:11:20-05	2026-03-27 04:11:25-05	2026-03-27 04:11:20-05
857	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.d422a565a6b22a794a423ce380949d131f97b6ed9d0d2dbd764d186f69961e06	2026-04-26 04:11:25-05	2026-03-27 04:32:02-05	2026-03-27 04:11:25-05
858	2	7e714d6d8bc201e47f5042559c1cc6214446e6b8.4fe51a2ba89a3d6c2e4e81245f1230059d0496c02bc6278fd9e293f03aba44db	2026-04-26 04:32:15-05	2026-03-27 05:07:50-05	2026-03-27 04:32:15-05
859	2	7e714d6d8bc201e47f5042559c1cc6214446e6b8.3643040d3eb58e55ee7de314f012ae0affe89ec85718a52e4fccd671a9529b7d	2026-04-26 05:07:50-05	2026-03-27 05:27:09-05	2026-03-27 05:07:50-05
860	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.5ab9fdfe0a24b3b9e27cde0e3d14d66e2a6d8d290101c58deb7a66cdeb2698e8	2026-04-26 05:27:14-05	2026-03-27 05:41:39-05	2026-03-27 05:27:14-05
861	2	7e714d6d8bc201e47f5042559c1cc6214446e6b8.5920a0f3786740e8588e65dfda4e513d214af7512039823df3990d15d53d2bb2	2026-04-26 05:41:47-05	2026-03-27 05:42:17-05	2026-03-27 05:41:47-05
862	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.7817b39fda5821a5fc21212a3124d7d162d15553795be2630cabc221fc3fe3bd	2026-04-26 05:42:21-05	2026-03-27 05:46:57-05	2026-03-27 05:42:21-05
863	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.977fc3e07796eaa9b6b4fcc13df59cf053b367615454965f9cc4c2c0ff478ead	2026-04-26 05:47:01-05	2026-03-27 07:06:30-05	2026-03-27 05:47:01-05
864	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.a9b7b6ae90aac1c2f968dc50c3c31cb6bebb41cb31e549e1829d703d13f92ad6	2026-04-26 07:06:30-05	2026-03-27 07:06:31-05	2026-03-27 07:06:30-05
865	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.62e3bafeb1de33c1df726b1b4a1f1c43ce32c2dd22bc9ac5e61f27b2020c9af0	2026-04-26 07:06:31-05	2026-03-27 07:06:36-05	2026-03-27 07:06:31-05
866	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.81887d3561060476c823dcf3913af14e2bd5fb3343509223d5bfb912ccce706d	2026-04-26 07:06:36-05	2026-03-27 15:42:28-05	2026-03-27 07:06:36-05
867	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.7652e798c92e7549f5c61b151513414aca1cf5f2008ae841a102ba7ae9bd9b13	2026-04-26 15:42:28-05	2026-03-27 15:42:29-05	2026-03-27 15:42:28-05
868	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.2ef46fe283973834348df6abed07972f36e599f83caee57da904b48aeb3e7d0a	2026-04-26 15:42:29-05	2026-03-27 15:42:30-05	2026-03-27 15:42:29-05
869	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.26d5083947c5a02cfb745dcdcb104127f654b40d845ce5c435a033e8713c5ba1	2026-04-26 15:42:30-05	2026-03-27 15:42:32-05	2026-03-27 15:42:30-05
870	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.7c60c67350e15a3937d14ed6192fb7a3dac7137006c39f762138e5e9947824c5	2026-04-26 15:42:32-05	2026-03-27 15:42:33-05	2026-03-27 15:42:32-05
871	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.31571f09378f9be4306e86816a4b567cc7807d932f79f6d54bb58cde501405ad	2026-04-26 15:42:33-05	2026-03-27 15:42:34-05	2026-03-27 15:42:33-05
872	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.be8ae5f3262af2e8a7dd1f16960a3346d00b218655c35eb78c7b7e9f0bd4b8e2	2026-04-26 15:42:34-05	2026-03-27 15:42:35-05	2026-03-27 15:42:34-05
873	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.e02b86b69511e3f47cf5db36cb66893b34990e20d1fef9ddf4e4aeb285ce9232	2026-04-26 15:42:35-05	2026-03-27 15:42:41-05	2026-03-27 15:42:35-05
874	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.e09ccbf611069551aac55ea2cf865026c4344668ef8f85fc24c3f5a0e390d497	2026-04-26 15:42:41-05	2026-03-27 15:42:49-05	2026-03-27 15:42:41-05
875	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.5ad56e5b92ce8882fcd781591123b9347faa9a99e46d4db4627de1c3bd7bf7df	2026-04-26 15:42:49-05	2026-03-27 16:52:45-05	2026-03-27 15:42:49-05
876	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.1f17a9ad1b682a69cf6246976f3b123c64a32ebd083b899797f196d1a701a0a0	2026-04-26 16:52:45-05	2026-03-28 13:51:11-05	2026-03-27 16:52:45-05
877	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.082b0097afd1a6fde2190deacb16759a02289c083f6af3c5c26f3ac3acbf0c25	2026-04-27 13:51:11-05	2026-03-28 13:51:13-05	2026-03-28 13:51:11-05
878	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.bf0e549fd6378ac386eb4e8da87eaacb5f5c3ef06cd646fca2bfcac841ca8035	2026-04-27 13:51:13-05	2026-03-28 13:51:14-05	2026-03-28 13:51:13-05
879	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.2715b1d8d5566b123c409e5c51d0ef33f3a15af7124515d736b1eb064e193991	2026-04-27 13:51:14-05	2026-03-28 13:51:15-05	2026-03-28 13:51:14-05
880	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.67ef25bc77a942b97180ec065eea13e3051c9e653cc4b91651a11bc22c2a234a	2026-04-27 13:51:15-05	2026-03-28 13:51:16-05	2026-03-28 13:51:15-05
881	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.a58c5022e0957d77d49cd367fac8db6b41392d6ac36dd0fd7a91ba5a500b5ee7	2026-04-27 13:51:16-05	2026-03-28 13:51:17-05	2026-03-28 13:51:16-05
882	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.6f2769eadad4a1e0c7259aa3d8987030d4e1c58e1350b83026ca242066adaccd	2026-04-27 13:51:17-05	2026-03-28 13:51:18-05	2026-03-28 13:51:17-05
883	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.2094d083250509e88a2b3f33b7a7d07fbd95200b18d2bcd2e8285d44fd131540	2026-04-27 13:51:18-05	2026-03-28 13:51:29-05	2026-03-28 13:51:18-05
884	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.1632918e5a1acb9d396d54d9ba09d71e596b1efe47f08d3cd795dd57ee31f46a	2026-04-27 13:51:29-05	2026-04-04 10:29:35-05	2026-03-28 13:51:29-05
885	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.523a47d8bc8e43bbbd45d5baa0dd9edada644880135e50a5ea901f3fd96c769e	2026-05-04 10:29:35-05	2026-04-04 11:01:14-05	2026-04-04 10:29:35-05
886	1	7e714d6d8bc201e47f5042559c1cc6214446e6b8.8b71576af2761d2f1af2e2e37dd1584a9b53adcaf678937aefc1ff1b13eadfbf	2026-05-04 11:01:14-05	\N	2026-04-04 11:01:14-05
\.


--
-- Data for Name: role_module_access; Type: TABLE DATA; Schema: auth; Owner: postgres
--

COPY auth.role_module_access (role_id, module_id, can_view, can_create, can_update, can_delete, can_export, can_approve, field_rules, data_scope_rules, updated_at) FROM stdin;
1	1	t	t	t	t	t	t	\N	\N	2026-03-10 21:21:33.764975-05
1	2	t	t	t	t	t	t	\N	\N	2026-03-10 21:21:33.764975-05
1	3	t	t	t	t	t	t	\N	\N	2026-03-10 23:05:31.480601-05
2	1	t	t	f	f	t	f	\N	\N	2026-03-20 11:19:20.972141-05
2	2	t	f	f	f	f	f	\N	\N	2026-03-20 11:19:20.972141-05
2	3	t	f	f	f	f	f	\N	\N	2026-03-20 11:19:20.972141-05
3	2	t	f	t	f	f	f	\N	\N	2026-03-26 05:30:32-05
3	3	t	f	f	f	f	f	\N	\N	2026-03-26 05:30:32-05
3	1	t	t	f	f	t	f	\N	\N	2026-03-26 05:30:32-05
\.


--
-- Data for Name: role_permissions; Type: TABLE DATA; Schema: auth; Owner: postgres
--

COPY auth.role_permissions (role_id, permission_id) FROM stdin;
\.


--
-- Data for Name: role_ui_field_access; Type: TABLE DATA; Schema: auth; Owner: postgres
--

COPY auth.role_ui_field_access (role_id, field_id, can_view, can_edit, can_filter) FROM stdin;
\.


--
-- Data for Name: roles; Type: TABLE DATA; Schema: auth; Owner: postgres
--

COPY auth.roles (id, company_id, code, name, status) FROM stdin;
1	1	ADMIN	Administrador	1
2	1	VENDEDOR	Vendedor Evento	1
3	1	CAJERO	Caja Evento	1
\.


--
-- Data for Name: user_module_overrides; Type: TABLE DATA; Schema: auth; Owner: postgres
--

COPY auth.user_module_overrides (user_id, module_id, can_view, can_create, can_update, can_delete, can_export, can_approve, field_rules, data_scope_rules, updated_at) FROM stdin;
\.


--
-- Data for Name: user_roles; Type: TABLE DATA; Schema: auth; Owner: postgres
--

COPY auth.user_roles (user_id, role_id) FROM stdin;
1	1
2	2
3	3
\.


--
-- Data for Name: user_ui_field_access; Type: TABLE DATA; Schema: auth; Owner: postgres
--

COPY auth.user_ui_field_access (user_id, field_id, can_view, can_edit, can_filter) FROM stdin;
\.


--
-- Data for Name: users; Type: TABLE DATA; Schema: auth; Owner: postgres
--

COPY auth.users (id, company_id, branch_id, username, password_hash, first_name, last_name, email, phone, status, last_login_at, created_at, updated_at, deleted_at) FROM stdin;
3	1	1	caja_demo	$2y$10$DvUMKOMvfT32M3gwGLGD2.4ddiskMfu.nfQT2kwKeiyQZOCDdMP1.	Caja	Demo	caja@demo.local	\N	1	2026-03-27 02:26:51-05	2026-03-20 11:11:40.73812-05	2026-03-27 02:26:51-05	\N
2	1	1	vendedor_demo	$2y$10$DvUMKOMvfT32M3gwGLGD2.4ddiskMfu.nfQT2kwKeiyQZOCDdMP1.	Vendedor	Demo	vendedor@demo.local	\N	1	2026-03-27 05:41:47-05	2026-03-20 11:11:40.73812-05	2026-03-27 05:41:47-05	\N
1	1	1	admin	$2y$10$nF/XtkduomCfsJZwZQke9uh6eU.KEpGgG4FxFsLkJeGrX8MxnjdMe	Admin	Sistema	admin@demo.local	\N	1	2026-04-04 10:29:35-05	2026-03-10 21:21:33.764975-05	2026-04-04 10:29:35-05	\N
\.


--
-- Data for Name: documents; Type: TABLE DATA; Schema: billing; Owner: postgres
--

COPY billing.documents (id, company_id, source_order_id, doc_type, series, number, issue_at, customer_id, currency_id, subtotal, tax_total, total, status, created_at) FROM stdin;
\.


--
-- Data for Name: branches; Type: TABLE DATA; Schema: core; Owner: postgres
--

COPY core.branches (id, company_id, code, name, address, is_main, status, created_at, updated_at) FROM stdin;
1	1	001	PRINCIPAL	\N	t	1	2026-03-10 21:21:33.764975-05	2026-03-10 21:21:33.764975-05
\.


--
-- Data for Name: companies; Type: TABLE DATA; Schema: core; Owner: postgres
--

COPY core.companies (id, tax_id, legal_name, trade_name, email, phone, address, status, created_at, updated_at) FROM stdin;
1	10455923951	MSEP PERU SAC	DEMO	\N	\N	\N	1	2026-03-10 21:21:33.764975-05	2026-03-10 21:21:33.764975-05
\.


--
-- Data for Name: company_igv_rates; Type: TABLE DATA; Schema: core; Owner: postgres
--

COPY core.company_igv_rates (id, company_id, name, rate_percent, is_active, effective_from, created_at, updated_at) FROM stdin;
1	1	IGV 18.00%	18.0000	t	2026-04-04	2026-04-04 10:36:55.376928-05	2026-04-04 10:36:55.376928-05
\.


--
-- Data for Name: company_settings; Type: TABLE DATA; Schema: core; Owner: postgres
--

COPY core.company_settings (company_id, address, phone, email, website, logo_path, cert_path, cert_password_enc, bank_accounts, extra_data, updated_at) FROM stdin;
1	AV. PRINCIPAL 123	+51 1 2345678	info@empresademo.com	\N	\N	certs/company_1.pfx	eyJpdiI6InFGYVRpNXdpWUFTUFQvVjJKTnJrTGc9PSIsInZhbHVlIjoiUWk2eGUzMnc1aFJORTdCazk2dVA3dz09IiwibWFjIjoiNDhhZDQ5MzhiZTA4YWRmNDBhOTE0NmMwZDQ1MTQ5ZDViZjFiYzkyZmJmMjU3MDg4ZjVjNGQ0ZDllN2RiODNmOCIsInRhZyI6IiJ9	[]	{"ubigeo": "150131", "distrito": "SAN ISIDRO", "provincia": "LIMA", "codigolocal": "0000", "departamento": "LIMA", "urbanizacion": "ORRANTIA", "telefono_fijo": "+51 1 2345678", "sunat_secondary_pass": "Moddatos", "sunat_secondary_user": "MODDATOS"}	2026-04-04 11:04:05-05
\.


--
-- Data for Name: currencies; Type: TABLE DATA; Schema: core; Owner: postgres
--

COPY core.currencies (id, code, name, symbol, is_default, status) FROM stdin;
1	PEN	SOL	S/	t	1
\.


--
-- Data for Name: payment_methods; Type: TABLE DATA; Schema: core; Owner: postgres
--

COPY core.payment_methods (id, code, name, status) FROM stdin;
1	CASH	Efectivo	1
3	CRE	Credito	1
\.


--
-- Data for Name: tax_categories; Type: TABLE DATA; Schema: core; Owner: postgres
--

COPY core.tax_categories (id, code, name, tax_tribute_code, rate_percent, status, company_id, created_at, updated_at) FROM stdin;
1	10	Gravado - Operacion Onerosa	1000	18.0000	1	\N	2026-03-11 22:34:42.00425	2026-03-11 22:34:42.00425
2	11	Gravado - Retiro por premio	9996	18.0000	1	\N	2026-03-11 22:34:42.00425	2026-03-11 22:34:42.00425
3	12	Gravado - Retiro por donacion	9996	18.0000	1	\N	2026-03-11 22:34:42.00425	2026-03-11 22:34:42.00425
4	13	Gravado - Retiro	9996	18.0000	1	\N	2026-03-11 22:34:42.00425	2026-03-11 22:34:42.00425
5	14	Gravado - Retiro por publicidad	9996	18.0000	1	\N	2026-03-11 22:34:42.00425	2026-03-11 22:34:42.00425
6	15	Gravado - Bonificaciones	9996	18.0000	1	\N	2026-03-11 22:34:42.00425	2026-03-11 22:34:42.00425
7	16	Gravado - Retiro por entrega a trabajadores	9996	18.0000	1	\N	2026-03-11 22:34:42.00425	2026-03-11 22:34:42.00425
8	20	Exonerado - Operacion Onerosa	9997	0.0000	1	\N	2026-03-11 22:34:42.00425	2026-03-11 22:34:42.00425
9	30	Inafecto - Operacion Onerosa	9998	0.0000	1	\N	2026-03-11 22:34:42.00425	2026-03-11 22:34:42.00425
10	31	Inafecto - Retiro por Bonificacion	9996	0.0000	1	\N	2026-03-11 22:34:42.00425	2026-03-11 22:34:42.00425
11	32	Inafecto - Retiro	9996	0.0000	1	\N	2026-03-11 22:34:42.00425	2026-03-11 22:34:42.00425
12	33	Inafecto - Retiro por Muestras Medicas	9996	0.0000	1	\N	2026-03-11 22:34:42.00425	2026-03-11 22:34:42.00425
13	34	Inafecto - Retiro por Convenio Colectivo	9996	0.0000	1	\N	2026-03-11 22:34:42.00425	2026-03-11 22:34:42.00425
14	35	Inafecto - Retiro por premio	9996	0.0000	1	\N	2026-03-11 22:34:42.00425	2026-03-11 22:34:42.00425
15	36	Inafecto - Retiro por publicidad	9996	0.0000	1	\N	2026-03-11 22:34:42.00425	2026-03-11 22:34:42.00425
16	40	Exportacion	9996	0.0000	1	\N	2026-03-11 22:34:42.00425	2026-03-11 22:34:42.00425
17	17	Gravado - IVAP	9996	4.0000	1	\N	2026-03-11 22:34:42.00425	2026-03-11 22:34:42.00425
18	21	Exonerado - Transferencia gratuita	9996	0.0000	1	\N	2026-03-11 22:34:42.00425	2026-03-11 22:34:42.00425
19	37	Inafecto - Transferencia gratuita	9996	0.0000	1	\N	2026-03-11 22:34:42.00425	2026-03-11 22:34:42.00425
\.


--
-- Data for Name: units; Type: TABLE DATA; Schema: core; Owner: postgres
--

COPY core.units (id, code, sunat_uom_code, name, status) FROM stdin;
1	4A	4A	BOBINAS	1
2	BJ	BJ	BALDE	1
3	BLL	BLL	BARRILES	1
4	BG	BG	BOLSA	1
5	BO	BO	BOTELLAS	1
6	BX	BX	CAJA	1
7	CT	CT	CARTONES	1
8	CMK	CMK	CENTIMETRO CUADRADO	1
9	CMQ	CMQ	CENTIMETRO CUBICO	1
10	CMT	CMT	CENTIMETRO LINEAL	1
11	CEN	CEN	CIENTO DE UNIDADES	1
12	CY	CY	CILINDRO	1
13	CJ	CJ	CONOS	1
14	DZN	DZN	DOCENA	1
15	DZP	DZP	DOCENA POR 10**6	1
16	BE	BE	FARDO	1
17	GLI	GLI	GALON INGLES (4,545956L)	1
18	GRM	GRM	GRAMO	1
19	GRO	GRO	GRUESA	1
20	HLT	HLT	HECTOLITRO	1
21	LEF	LEF	HOJA	1
22	SET	SET	JUEGO	1
23	KGM	KGM	KILOGRAMO	1
24	KTM	KTM	KILOMETRO	1
25	KWH	KWH	KILOVATIO HORA	1
26	KT	KT	KIT	1
27	CA	CA	LATAS	1
28	LBR	LBR	LIBRAS	1
29	LTR	LTR	LITRO	1
30	MWH	MWH	MEGAWATT HORA	1
31	MTR	MTR	METRO	1
32	MTK	MTK	METRO CUADRADO	1
33	MTQ	MTQ	METRO CUBICO	1
34	MGM	MGM	MILIGRAMOS	1
35	MLT	MLT	MILILITRO	1
36	MMT	MMT	MILIMETRO	1
37	MMK	MMK	MILIMETRO CUADRADO	1
38	MMQ	MMQ	MILIMETRO CUBICO	1
39	MLL	MLL	MILLARES	1
40	UM	UM	MILLON DE UNIDADES	1
41	ONZ	ONZ	ONZAS	1
42	PF	PF	PALETAS	1
43	PK	PK	PAQUETE	1
44	PR	PR	PAR	1
45	FOT	FOT	PIES	1
46	FTK	FTK	PIES CUADRADOS	1
47	FTQ	FTQ	PIES CUBICOS	1
48	C62	C62	PIEZAS	1
49	PG	PG	PLACAS	1
50	ST	ST	PLIEGO	1
51	INH	INH	PULGADAS	1
52	RM	RM	RESMA	1
53	DR	DR	TAMBOR	1
54	STN	STN	TONELADA CORTA	1
55	LTN	LTN	TONELADA LARGA	1
56	TNE	TNE	TONELADAS	1
57	TU	TU	TUBOS	1
58	NIU	NIU	UNIDAD (BIENES)	1
59	ZZ	ZZ	UNIDAD (SERVICIOS)	1
60	GLL	GLL	US GALON (3,7843 L)	1
61	YRD	YRD	YARDA	1
62	YDK	YDK	YARDA CUADRADA	1
63	VA	VA	VARIOS	1
\.


--
-- Data for Name: categories; Type: TABLE DATA; Schema: inventory; Owner: postgres
--

COPY inventory.categories (id, company_id, name, status) FROM stdin;
1	1	GENERAL	1
\.


--
-- Data for Name: inventory_ledger; Type: TABLE DATA; Schema: inventory; Owner: postgres
--

COPY inventory.inventory_ledger (id, company_id, warehouse_id, product_id, lot_id, movement_type, quantity, unit_cost, ref_type, ref_id, notes, moved_at, created_by) FROM stdin;
2	1	1	1	\N	ADJUST	20.000	10.0000	STOCK_ENTRY	3	\N	2026-03-14 14:10:26-05	1
4	1	1	1	\N	OUT	5.000	0.0000	COMMERCIAL_DOCUMENT	12	Doc INVOICE F001-2	2026-03-14 00:00:00-05	1
5	1	1	1	\N	OUT	1.000	0.0000	COMMERCIAL_DOCUMENT	13	Doc INVOICE F001-3	2026-03-14 00:00:00-05	1
6	1	1	1	\N	OUT	2.000	0.0000	COMMERCIAL_DOCUMENT	14	Doc RECEIPT B001-5	2026-03-14 00:00:00-05	1
7	1	1	1	\N	OUT	1.000	0.0000	COMMERCIAL_DOCUMENT	15	Doc RECEIPT B001-6	2026-03-14 00:00:00-05	1
9	1	1	1	\N	OUT	1.000	0.0000	COMMERCIAL_DOCUMENT	18	Doc RECEIPT B001-8	2026-03-14 00:00:00-05	1
10	1	1	1	\N	OUT	1.000	0.0000	COMMERCIAL_DOCUMENT	19	Doc INVOICE F001-4	2026-03-18 00:00:00-05	1
11	1	1	1	\N	OUT	1.000	0.0000	COMMERCIAL_DOCUMENT	20	Doc INVOICE F001-5	2026-03-18 00:00:00-05	1
12	1	1	1	\N	OUT	1.000	0.0000	COMMERCIAL_DOCUMENT	21	Doc RECEIPT B001-9	2026-03-19 00:00:00-05	1
13	1	1	1	\N	OUT	1.000	0.0000	COMMERCIAL_DOCUMENT	22	Doc RECEIPT B001-10	2026-03-19 00:00:00-05	1
14	1	1	1	\N	OUT	1.000	0.0000	COMMERCIAL_DOCUMENT	26	Doc INVOICE F001-6	2026-03-20 13:57:38.92-05	3
15	1	1	1	\N	OUT	1.000	0.0000	COMMERCIAL_DOCUMENT	27	Doc INVOICE F001-7	2026-03-20 00:00:00-05	2
16	1	1	1	\N	OUT	1.000	0.0000	COMMERCIAL_DOCUMENT	28	Doc RECEIPT B001-11	2026-03-21 00:00:00-05	1
17	1	1	1	\N	OUT	1.000	0.0000	COMMERCIAL_DOCUMENT	30	Doc INVOICE F001-8	2026-03-21 08:47:26.161-05	3
18	1	1	1	\N	OUT	1.000	0.0000	COMMERCIAL_DOCUMENT	31	Doc RECEIPT B001-12	2026-03-21 00:00:00-05	1
19	1	1	1	\N	OUT	1.000	0.0000	COMMERCIAL_DOCUMENT	34	Doc INVOICE F001-9	2026-03-21 15:21:12.072-05	3
20	1	1	3	1	IN	50.000	20.0000	STOCK_ENTRY	5	\N	2026-03-26 04:29:18-05	1
21	1	1	3	1	OUT	10.000	0.0000	COMMERCIAL_DOCUMENT	35	Doc RECEIPT B001-13	2026-03-25 00:00:00-05	1
22	1	1	3	1	OUT	10.000	0.0000	COMMERCIAL_DOCUMENT	37	Doc INVOICE F001-10	2026-03-25 23:52:03.694-05	3
23	1	1	3	1	OUT	1.000	0.0000	COMMERCIAL_DOCUMENT	38	Doc Boleta B001-14	2026-04-04 10:39:09-05	1
24	1	1	1	\N	OUT	10.000	0.0000	COMMERCIAL_DOCUMENT	39	Doc Boleta B001-15	2026-04-04 10:59:16-05	1
25	1	1	3	1	OUT	5.000	0.0000	COMMERCIAL_DOCUMENT	40	Doc Factura F001-11	2026-04-04 11:07:31-05	1
\.


--
-- Data for Name: inventory_settings; Type: TABLE DATA; Schema: inventory; Owner: postgres
--

COPY inventory.inventory_settings (company_id, inventory_mode, lot_outflow_strategy, allow_negative_stock, enforce_lot_for_tracked, updated_at, complexity_mode, enable_inventory_pro, enable_lot_tracking, enable_expiry_tracking, enable_advanced_reporting, enable_graphical_dashboard, enable_location_control) FROM stdin;
1	KARDEX_SIMPLE	MANUAL	t	f	2026-04-04 10:38:50-05	ADVANCED	t	t	t	t	t	t
\.


--
-- Data for Name: lot_expiry_projection; Type: TABLE DATA; Schema: inventory; Owner: postgres
--

COPY inventory.lot_expiry_projection (company_id, warehouse_id, product_id, lot_id, branch_id, lot_code, manufacture_at, expires_at, stock, unit_cost, stock_value, expiry_bucket, days_to_expire, updated_at) FROM stdin;
\.


--
-- Data for Name: outbox_events; Type: TABLE DATA; Schema: inventory; Owner: postgres
--

COPY inventory.outbox_events (id, company_id, aggregate_type, aggregate_id, event_type, payload_json, status, attempts, available_at, processed_at, last_error, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: product_brands; Type: TABLE DATA; Schema: inventory; Owner: postgres
--

COPY inventory.product_brands (id, company_id, name, status, created_by, created_at, updated_at) FROM stdin;
1	1	1	1	1	2026-03-27 05:30:38-05	2026-03-27 05:30:38-05
2	1	2	1	1	2026-03-27 05:52:02-05	2026-03-27 05:52:02-05
\.


--
-- Data for Name: product_lines; Type: TABLE DATA; Schema: inventory; Owner: postgres
--

COPY inventory.product_lines (id, company_id, name, status, created_by, created_at, updated_at) FROM stdin;
1	1	23	1	1	2026-03-27 05:41:34-05	2026-03-27 05:41:34-05
\.


--
-- Data for Name: product_locations; Type: TABLE DATA; Schema: inventory; Owner: postgres
--

COPY inventory.product_locations (id, company_id, name, status, created_by, created_at, updated_at) FROM stdin;
1	1	1	1	1	2026-03-27 05:41:27-05	2026-03-27 05:41:27-05
\.


--
-- Data for Name: product_lots; Type: TABLE DATA; Schema: inventory; Owner: postgres
--

COPY inventory.product_lots (id, company_id, warehouse_id, product_id, lot_code, manufacture_at, expires_at, received_at, unit_cost, supplier_reference, status, created_by, created_at) FROM stdin;
1	1	1	3	L001	\N	\N	2026-03-26 04:29:18-05	\N	\N	1	1	2026-03-26 04:29:18-05
\.


--
-- Data for Name: product_recipe_items; Type: TABLE DATA; Schema: inventory; Owner: postgres
--

COPY inventory.product_recipe_items (id, recipe_id, component_product_id, qty_required, waste_percent, notes) FROM stdin;
\.


--
-- Data for Name: product_recipes; Type: TABLE DATA; Schema: inventory; Owner: postgres
--

COPY inventory.product_recipes (id, company_id, output_product_id, code, name, output_qty, status, created_by, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: product_sale_units; Type: TABLE DATA; Schema: inventory; Owner: postgres
--

COPY inventory.product_sale_units (company_id, product_id, unit_id, is_base, status, updated_by, updated_at) FROM stdin;
\.


--
-- Data for Name: product_uom_conversions; Type: TABLE DATA; Schema: inventory; Owner: postgres
--

COPY inventory.product_uom_conversions (id, company_id, product_id, from_unit_id, to_unit_id, conversion_factor, status, created_at) FROM stdin;
\.


--
-- Data for Name: product_warranties; Type: TABLE DATA; Schema: inventory; Owner: postgres
--

COPY inventory.product_warranties (id, company_id, name, status, created_by, created_at, updated_at) FROM stdin;
1	1	2	1	1	2026-03-27 05:41:31-05	2026-03-27 05:41:31-05
\.


--
-- Data for Name: products; Type: TABLE DATA; Schema: inventory; Owner: postgres
--

COPY inventory.products (id, company_id, sku, barcode, category_id, unit_id, name, description, sale_price, cost_price, is_stockable, lot_tracking, has_expiration, multi_uom, status, created_at, updated_at, deleted_at, line_id, brand_id, location_id, warranty_id, product_nature, sunat_code, image_url, seller_commission_percent) FROM stdin;
1	1	\N	\N	1	58	PRODUCTO1	\N	10.00	0.00	t	f	f	f	1	2026-03-11 21:51:49.503789-05	2026-03-11 21:51:49.503789-05	\N	\N	\N	\N	\N	PRODUCT	\N	\N	0.0000
2	1	\N	\N	1	59	SERVICIO	\N	20.00	0.00	t	f	f	f	1	2026-03-11 21:52:17.291393-05	2026-03-11 21:52:17.291393-05	\N	\N	\N	\N	\N	PRODUCT	\N	\N	0.0000
3	1	\N	\N	1	58	PRODUCTO2	\N	30.00	20.00	t	t	t	f	1	2026-03-25 22:59:36.182157-05	2026-03-25 22:59:36.182157-05	\N	\N	\N	\N	\N	PRODUCT	\N	\N	0.0000
\.


--
-- Data for Name: report_requests; Type: TABLE DATA; Schema: inventory; Owner: postgres
--

COPY inventory.report_requests (id, company_id, branch_id, requested_by, report_type, filters_json, status, result_json, error_message, requested_at, started_at, finished_at, created_at, updated_at) FROM stdin;
1	1	1	1	KARDEX_VALUED	{"warehouse_id": 1}	COMPLETED	{"rows": [{"id": 20, "lot_id": 1, "ref_id": 5, "lot_code": "L001", "moved_at": "2026-03-26 04:29:18-05", "quantity": "50.000", "ref_type": "STOCK_ENTRY", "unit_cost": "20.0000", "line_total": "1000.0000000", "product_id": 3, "product_sku": null, "product_name": "PRODUCTO2", "warehouse_id": 1, "movement_type": "IN", "warehouse_code": "WH01", "warehouse_name": "ALMACEN PRINCIPAL"}, {"id": 22, "lot_id": 1, "ref_id": 37, "lot_code": "L001", "moved_at": "2026-03-25 23:52:03.694-05", "quantity": "10.000", "ref_type": "COMMERCIAL_DOCUMENT", "unit_cost": "0.0000", "line_total": "0.0000000", "product_id": 3, "product_sku": null, "product_name": "PRODUCTO2", "warehouse_id": 1, "movement_type": "OUT", "warehouse_code": "WH01", "warehouse_name": "ALMACEN PRINCIPAL"}, {"id": 21, "lot_id": 1, "ref_id": 35, "lot_code": "L001", "moved_at": "2026-03-25 00:00:00-05", "quantity": "10.000", "ref_type": "COMMERCIAL_DOCUMENT", "unit_cost": "0.0000", "line_total": "0.0000000", "product_id": 3, "product_sku": null, "product_name": "PRODUCTO2", "warehouse_id": 1, "movement_type": "OUT", "warehouse_code": "WH01", "warehouse_name": "ALMACEN PRINCIPAL"}, {"id": 19, "lot_id": null, "ref_id": 34, "lot_code": null, "moved_at": "2026-03-21 15:21:12.072-05", "quantity": "1.000", "ref_type": "COMMERCIAL_DOCUMENT", "unit_cost": "0.0000", "line_total": "0.0000000", "product_id": 1, "product_sku": null, "product_name": "PRODUCTO1", "warehouse_id": 1, "movement_type": "OUT", "warehouse_code": "WH01", "warehouse_name": "ALMACEN PRINCIPAL"}, {"id": 17, "lot_id": null, "ref_id": 30, "lot_code": null, "moved_at": "2026-03-21 08:47:26.161-05", "quantity": "1.000", "ref_type": "COMMERCIAL_DOCUMENT", "unit_cost": "0.0000", "line_total": "0.0000000", "product_id": 1, "product_sku": null, "product_name": "PRODUCTO1", "warehouse_id": 1, "movement_type": "OUT", "warehouse_code": "WH01", "warehouse_name": "ALMACEN PRINCIPAL"}, {"id": 18, "lot_id": null, "ref_id": 31, "lot_code": null, "moved_at": "2026-03-21 00:00:00-05", "quantity": "1.000", "ref_type": "COMMERCIAL_DOCUMENT", "unit_cost": "0.0000", "line_total": "0.0000000", "product_id": 1, "product_sku": null, "product_name": "PRODUCTO1", "warehouse_id": 1, "movement_type": "OUT", "warehouse_code": "WH01", "warehouse_name": "ALMACEN PRINCIPAL"}, {"id": 16, "lot_id": null, "ref_id": 28, "lot_code": null, "moved_at": "2026-03-21 00:00:00-05", "quantity": "1.000", "ref_type": "COMMERCIAL_DOCUMENT", "unit_cost": "0.0000", "line_total": "0.0000000", "product_id": 1, "product_sku": null, "product_name": "PRODUCTO1", "warehouse_id": 1, "movement_type": "OUT", "warehouse_code": "WH01", "warehouse_name": "ALMACEN PRINCIPAL"}, {"id": 14, "lot_id": null, "ref_id": 26, "lot_code": null, "moved_at": "2026-03-20 13:57:38.92-05", "quantity": "1.000", "ref_type": "COMMERCIAL_DOCUMENT", "unit_cost": "0.0000", "line_total": "0.0000000", "product_id": 1, "product_sku": null, "product_name": "PRODUCTO1", "warehouse_id": 1, "movement_type": "OUT", "warehouse_code": "WH01", "warehouse_name": "ALMACEN PRINCIPAL"}, {"id": 15, "lot_id": null, "ref_id": 27, "lot_code": null, "moved_at": "2026-03-20 00:00:00-05", "quantity": "1.000", "ref_type": "COMMERCIAL_DOCUMENT", "unit_cost": "0.0000", "line_total": "0.0000000", "product_id": 1, "product_sku": null, "product_name": "PRODUCTO1", "warehouse_id": 1, "movement_type": "OUT", "warehouse_code": "WH01", "warehouse_name": "ALMACEN PRINCIPAL"}, {"id": 13, "lot_id": null, "ref_id": 22, "lot_code": null, "moved_at": "2026-03-19 00:00:00-05", "quantity": "1.000", "ref_type": "COMMERCIAL_DOCUMENT", "unit_cost": "0.0000", "line_total": "0.0000000", "product_id": 1, "product_sku": null, "product_name": "PRODUCTO1", "warehouse_id": 1, "movement_type": "OUT", "warehouse_code": "WH01", "warehouse_name": "ALMACEN PRINCIPAL"}, {"id": 12, "lot_id": null, "ref_id": 21, "lot_code": null, "moved_at": "2026-03-19 00:00:00-05", "quantity": "1.000", "ref_type": "COMMERCIAL_DOCUMENT", "unit_cost": "0.0000", "line_total": "0.0000000", "product_id": 1, "product_sku": null, "product_name": "PRODUCTO1", "warehouse_id": 1, "movement_type": "OUT", "warehouse_code": "WH01", "warehouse_name": "ALMACEN PRINCIPAL"}, {"id": 11, "lot_id": null, "ref_id": 20, "lot_code": null, "moved_at": "2026-03-18 00:00:00-05", "quantity": "1.000", "ref_type": "COMMERCIAL_DOCUMENT", "unit_cost": "0.0000", "line_total": "0.0000000", "product_id": 1, "product_sku": null, "product_name": "PRODUCTO1", "warehouse_id": 1, "movement_type": "OUT", "warehouse_code": "WH01", "warehouse_name": "ALMACEN PRINCIPAL"}, {"id": 10, "lot_id": null, "ref_id": 19, "lot_code": null, "moved_at": "2026-03-18 00:00:00-05", "quantity": "1.000", "ref_type": "COMMERCIAL_DOCUMENT", "unit_cost": "0.0000", "line_total": "0.0000000", "product_id": 1, "product_sku": null, "product_name": "PRODUCTO1", "warehouse_id": 1, "movement_type": "OUT", "warehouse_code": "WH01", "warehouse_name": "ALMACEN PRINCIPAL"}, {"id": 2, "lot_id": null, "ref_id": 3, "lot_code": null, "moved_at": "2026-03-14 14:10:26-05", "quantity": "20.000", "ref_type": "STOCK_ENTRY", "unit_cost": "10.0000", "line_total": "200.0000000", "product_id": 1, "product_sku": null, "product_name": "PRODUCTO1", "warehouse_id": 1, "movement_type": "ADJUST", "warehouse_code": "WH01", "warehouse_name": "ALMACEN PRINCIPAL"}, {"id": 9, "lot_id": null, "ref_id": 18, "lot_code": null, "moved_at": "2026-03-14 00:00:00-05", "quantity": "1.000", "ref_type": "COMMERCIAL_DOCUMENT", "unit_cost": "0.0000", "line_total": "0.0000000", "product_id": 1, "product_sku": null, "product_name": "PRODUCTO1", "warehouse_id": 1, "movement_type": "OUT", "warehouse_code": "WH01", "warehouse_name": "ALMACEN PRINCIPAL"}, {"id": 7, "lot_id": null, "ref_id": 15, "lot_code": null, "moved_at": "2026-03-14 00:00:00-05", "quantity": "1.000", "ref_type": "COMMERCIAL_DOCUMENT", "unit_cost": "0.0000", "line_total": "0.0000000", "product_id": 1, "product_sku": null, "product_name": "PRODUCTO1", "warehouse_id": 1, "movement_type": "OUT", "warehouse_code": "WH01", "warehouse_name": "ALMACEN PRINCIPAL"}, {"id": 6, "lot_id": null, "ref_id": 14, "lot_code": null, "moved_at": "2026-03-14 00:00:00-05", "quantity": "2.000", "ref_type": "COMMERCIAL_DOCUMENT", "unit_cost": "0.0000", "line_total": "0.0000000", "product_id": 1, "product_sku": null, "product_name": "PRODUCTO1", "warehouse_id": 1, "movement_type": "OUT", "warehouse_code": "WH01", "warehouse_name": "ALMACEN PRINCIPAL"}, {"id": 5, "lot_id": null, "ref_id": 13, "lot_code": null, "moved_at": "2026-03-14 00:00:00-05", "quantity": "1.000", "ref_type": "COMMERCIAL_DOCUMENT", "unit_cost": "0.0000", "line_total": "0.0000000", "product_id": 1, "product_sku": null, "product_name": "PRODUCTO1", "warehouse_id": 1, "movement_type": "OUT", "warehouse_code": "WH01", "warehouse_name": "ALMACEN PRINCIPAL"}, {"id": 4, "lot_id": null, "ref_id": 12, "lot_code": null, "moved_at": "2026-03-14 00:00:00-05", "quantity": "5.000", "ref_type": "COMMERCIAL_DOCUMENT", "unit_cost": "0.0000", "line_total": "0.0000000", "product_id": 1, "product_sku": null, "product_name": "PRODUCTO1", "warehouse_id": 1, "movement_type": "OUT", "warehouse_code": "WH01", "warehouse_name": "ALMACEN PRINCIPAL"}], "type": "KARDEX_VALUED", "summary": {"total_qty": 110, "total_rows": 19, "total_value": 1200}, "generated_at": "2026-03-27T07:29:46+00:00"}	\N	2026-03-27 07:29:46-05	2026-03-27 07:29:46-05	2026-03-27 07:29:46-05	2026-03-27 07:29:46-05	2026-03-27 07:29:46-05
\.


--
-- Data for Name: stock_daily_snapshot; Type: TABLE DATA; Schema: inventory; Owner: postgres
--

COPY inventory.stock_daily_snapshot (snapshot_date, company_id, branch_id, warehouse_id, product_id, lot_id, qty_in, qty_out, qty_net, value_in, value_out, value_net, movement_count, first_moved_at, last_moved_at, updated_at) FROM stdin;
\.


--
-- Data for Name: stock_entries; Type: TABLE DATA; Schema: inventory; Owner: postgres
--

COPY inventory.stock_entries (id, company_id, branch_id, warehouse_id, entry_type, reference_no, supplier_reference, issue_at, status, notes, created_by, updated_by, created_at, updated_at, payment_method_id) FROM stdin;
3	1	1	1	ADJUSTMENT	\N	\N	2026-03-14 14:10:26-05	APPLIED	\N	1	1	2026-03-14 14:10:26-05	2026-03-14 14:10:26-05	\N
5	1	1	1	PURCHASE	OC-001	10455923951	2026-03-26 04:29:18-05	APPLIED	\N	1	1	2026-03-26 04:29:18-05	2026-03-26 04:29:18-05	\N
\.


--
-- Data for Name: stock_entry_items; Type: TABLE DATA; Schema: inventory; Owner: postgres
--

COPY inventory.stock_entry_items (id, entry_id, product_id, lot_id, qty, unit_cost, notes, created_at, tax_category_id, tax_rate) FROM stdin;
2	3	1	\N	20.00000000	10.00000000	\N	2026-03-14 14:10:26-05	\N	0.0000
3	5	3	1	50.00000000	20.00000000	\N	2026-03-26 04:29:18-05	\N	0.0000
\.


--
-- Data for Name: stock_transformation_lines; Type: TABLE DATA; Schema: inventory; Owner: postgres
--

COPY inventory.stock_transformation_lines (id, transformation_id, line_type, product_id, unit_id, lot_id, qty, qty_base, conversion_factor, unit_cost, notes) FROM stdin;
\.


--
-- Data for Name: stock_transformations; Type: TABLE DATA; Schema: inventory; Owner: postgres
--

COPY inventory.stock_transformations (id, company_id, branch_id, warehouse_id, recipe_id, transformation_code, executed_at, status, notes, created_by, created_at) FROM stdin;
\.


--
-- Data for Name: transformation_settings; Type: TABLE DATA; Schema: inventory; Owner: postgres
--

COPY inventory.transformation_settings (company_id, is_enabled, auto_consume_components, allow_negative_components, updated_at) FROM stdin;
\.


--
-- Data for Name: warehouses; Type: TABLE DATA; Schema: inventory; Owner: postgres
--

COPY inventory.warehouses (id, company_id, branch_id, code, name, address, status) FROM stdin;
1	1	1	WH01	ALMACEN PRINCIPAL	\N	1
\.


--
-- Data for Name: additional_legends; Type: TABLE DATA; Schema: master; Owner: postgres
--

COPY master.additional_legends (id, code, description, status) FROM stdin;
1	1000	Monto en Letras	inactivo
2	1002	Leyenda \\"TRANSFERENCIA GRATUITA DE UN BIEN Y/O SERVICIO PRES	inactivo
3	2000	Leyenda \\"COMPROBANTE DE PERCEPCION\\"	inactivo
4	2001	Leyenda \\"BIENES TRANSFERIDOS EN LA AMAZONÍA REGIÓN SELVA P	inactivo
5	2002	Leyenda \\"SERVICIOS PRESTADOS EN LA AMAZONÍA REGIÓN SELVA P	inactivo
6	2003	Leyenda \\"CONTRATOS DE CONSTRUCCION EJECUTADOS EN LA AMAZONÍ	inactivo
7	2004	Leyenda \\"Agencia de Viaje - Paquete turístico\\"	inactivo
8	2005	Leyenda \\"Venta realizada por emiso itinerante\\"	inactivo
9	2006	Leyenda \\"Operacion sujeta a detracción\\"	inactivo
10	3000	banco de nación nro de cuenta	activo
11	3001	NUMERO DE CTA EN EL BN	activo
12	3002	Recursos Hidrobiológicos-Nombre y matrícula de la embarcac	inactivo
13	3003	Recursos Hidrobiológicos-Tipo y cantidad de es	inactivo
14	3004	Recursos Hidrobiológicos-Lugar de descarga	inactivo
15	3005	Recursos Hidrobiológicos-Fecha de descarga	inactivo
16	3006	Transporte Bienes vía terrestre-Numero Registro MTC	inactivo
17	3007	Transporte Bienes vía terrestre-configuración vehicular	inactivo
18	3008	Transporte Bienes vía terrestre-punto de origen	inactivo
19	3009	Transporte Bienes vía terrestre-punto de destino	inactivo
20	3010	Transporte Bienes vía terrestre-valor referencial prelimina	inactivo
21	4000	Beneficio hospedajes:Código País de emisión del pasaporte	inactivo
22	4001	Beneficio hospedajes:Código País de residencia del sujeto 	inactivo
23	4002	Beneficio Hospedajes:Fecha de ingreso al país	inactivo
24	4003	Beneficio Hospedajes:Fecha de ingreso al establecimiento	inactivo
25	4004	Beneficio Hospedajes:Fecha de salida al establecimiento	inactivo
26	4005	Beneficio Hospedajes:Número de días de permanencia	inactivo
27	4006	Beneficio Hospedajes:Fecha de consumo	inactivo
28	4007	Beneficio Hospedajes:Paquete turístico - Nombre y Apellidos	inactivo
29	4008	Beneficio Hospedajes:Tipo documento de identidad del hú  espe	inactivo
30	4009	Beneficio Hospedajes:Numero de documento de identidad de huá	inactivo
31	5000	Proveedores Estado: Numero de Expediente	inactivo
32	5001	Proveedores Estado: Código de unidad ejecutora	inactivo
33	5002	Proveedores Estado: N° de proceso de selección	inactivo
34	5003	Proveedores Estado: N° de contrato	inactivo
35	6000	Comercializacion de Oro:Código Único Concesión Minera	inactivo
36	6001	Comercializacion de Oro:N° declaración compromiso	inactivo
37	6002	Comercializacion de Oro:N° Reg. Especial .Comerci. Oro	inactivo
38	6003	Comercializacion de Oro:N° Resolución que autoriza Planta 	inactivo
39	6004	Comercializacion de Oro:Ley Mineral(%concent. oro)	inactivo
\.


--
-- Data for Name: credit_note_reasons; Type: TABLE DATA; Schema: master; Owner: postgres
--

COPY master.credit_note_reasons (id, code, description, is_deleted) FROM stdin;
1	01	Anulación de la operacion	0
2	02	Anulación por error en el RUC	0
3	03	Corrección por error en la descripcion	0
4	04	Descuento Global	0
5	05	Descuento por ítem	0
6	06	Devolución total	0
7	07	Devolución por ítem	0
8	08	Bonificación	0
9	09	Disminición en el valor	0
10	10	Otros conceptos	0
\.


--
-- Data for Name: debit_note_reasons; Type: TABLE DATA; Schema: master; Owner: postgres
--

COPY master.debit_note_reasons (id, code, description, is_deleted) FROM stdin;
1	01	Interes por mora	0
2	02	Aumento en el valor	0
3	03	Penalidades / Otros conceptos	0
\.


--
-- Data for Name: detraccion_service_codes; Type: TABLE DATA; Schema: master; Owner: postgres
--

COPY master.detraccion_service_codes (id, code, name, rate_percent, is_active) FROM stdin;
1	001	Azúcar y melaza de caña	10.00	1
2	003	Alcohol etílico	10.00	1
3	004	Recursos hidrobiológicos	4.00	1
4	005	Maíz amarillo duro	4.00	1
5	006	Arena y piedra	10.00	1
6	007	Residuos, subproductos, desechos, recortes y desperdicios	15.00	1
7	009	Carnes y despojos comestibles	4.00	1
8	010	Harina, polvo y pellets de pescado, crustáceos y demás invertebrados acuáticos	4.00	1
9	011	Madera	4.00	1
10	016	Aceite de pescado	10.00	1
11	019	Minerales metálicos no auríferos	10.00	1
12	020	Bienes inmuebles gravados con IGV	4.00	1
13	021	Oro y demás minerales metálicos auríferos y plata	10.00	1
14	022	Minerales no metálicos	10.00	1
15	023	Leche	4.00	1
16	024	Tabaco en rama	10.00	1
17	026	Intermediación laboral y tercerización	12.00	1
18	030	Contratos de construcción	4.00	1
19	031	Fabricación de bienes por encargo	10.00	1
20	034	Arrendamiento de bienes muebles	10.00	1
21	035	Mantenimiento y reparación de bienes muebles	12.00	1
22	036	Movimiento de carga	10.00	1
23	037	Otros servicios empresariales	12.00	1
24	039	Actividades de servicios relacionadas con la minería	10.00	1
25	040	Comisión mercantil	12.00	1
26	041	Servicio de fabricación de bienes a partir de insumos del cliente	10.00	1
27	042	Otros servicios gravados con el IGV	12.00	1
28	043	Transporte ferroviario de pasajeros	10.00	1
29	044	Actividades de agencias de aduana	10.00	1
30	045	Actividades de agencias de viaje	12.00	1
31	047	Demás servicios gravados con el IGV	12.00	1
\.


--
-- Data for Name: employee_roles; Type: TABLE DATA; Schema: master; Owner: postgres
--

COPY master.employee_roles (id, name, is_lawyer, status) FROM stdin;
1	ADMINISTRADOR	1	2
20	VENDEDOR	1	2
23	RUTH	1	2
\.


--
-- Data for Name: geo_ubigeo; Type: TABLE DATA; Schema: master; Owner: postgres
--

COPY master.geo_ubigeo (id, code, full_name, population_text, surface_text, latitude, longitude, status) FROM stdin;
1	010101	010101 - CHACHAPOYAS|CHACHAPOYAS|DEPARTAMENTO AMAZONAS	29,171	153.78	-6.000000	-78.000000	2
2	010102	010102 - ASUNCION|CHACHAPOYAS|DEPARTAMENTO AMAZONAS	288	25.71	-6.000000	-78.000000	2
3	010103	010103 - BALSAS|CHACHAPOYAS|DEPARTAMENTO AMAZONAS	1,644	357.09	-7.000000	-78.000000	2
4	010104	010104 - CHETO|CHACHAPOYAS|DEPARTAMENTO AMAZONAS	591	56.97	-6.000000	-78.000000	2
5	010105	010105 - CHILIQUIN|CHACHAPOYAS|DEPARTAMENTO AMAZONAS	687	143.43	-6.000000	-78.000000	2
6	010106	010106 - CHUQUIBAMBA|CHACHAPOYAS|DEPARTAMENTO AMAZONAS	2,064	278.63	-7.000000	-78.000000	2
7	010107	010107 - GRANADA|CHACHAPOYAS|DEPARTAMENTO AMAZONAS	379	181.41	-6.000000	-78.000000	2
8	010108	010108 - HUANCAS|CHACHAPOYAS|DEPARTAMENTO AMAZONAS	1,329	48.79	-6.000000	-78.000000	2
9	010109	010109 - LA JALCA|CHACHAPOYAS|DEPARTAMENTO AMAZONAS	5,513	380.39	-6.000000	-78.000000	2
10	010110	010110 - LEIMEBAMBA|CHACHAPOYAS|DEPARTAMENTO AMAZONAS	4,206	373.14	-7.000000	-78.000000	2
11	010111	010111 - LEVANTO|CHACHAPOYAS|DEPARTAMENTO AMAZONAS	862	77.54	-6.000000	-78.000000	2
12	010112	010112 - MAGDALENA|CHACHAPOYAS|DEPARTAMENTO AMAZONAS	782	135.47	-6.000000	-78.000000	2
13	010113	010113 - MARISCAL CASTILLA|CHACHAPOYAS|DEPARTAMENTO AMAZONAS	986	83.58	-7.000000	-78.000000	2
14	010114	010114 - MOLINOPAMPA|CHACHAPOYAS|DEPARTAMENTO AMAZONAS	2,750	333.86	-6.000000	-78.000000	2
15	010115	010115 - MONTEVIDEO|CHACHAPOYAS|DEPARTAMENTO AMAZONAS	572	119.01	-7.000000	-78.000000	2
16	010116	010116 - OLLEROS|CHACHAPOYAS|DEPARTAMENTO AMAZONAS	363	125.16	-6.000000	-78.000000	2
17	010117	010117 - QUINJALCA|CHACHAPOYAS|DEPARTAMENTO AMAZONAS	830	91.59	-6.000000	-78.000000	2
18	010118	010118 - SAN FRANCISCO DE DAGUAS|CHACHAPOYAS|DEPARTAMENTO AMAZONAS	351	47.41	-6.000000	-78.000000	2
19	010119	010119 - SAN ISIDRO DE MAINO|CHACHAPOYAS|DEPARTAMENTO AMAZONAS	703	101.67	-6.000000	-78.000000	2
20	010120	010120 - SOLOCO|CHACHAPOYAS|DEPARTAMENTO AMAZONAS	1,302	84.48	-6.000000	-78.000000	2
21	010121	010121 - SONCHE|CHACHAPOYAS|DEPARTAMENTO AMAZONAS	218	113.26	-6.000000	-78.000000	2
22	010201	010201 - BAGUA|BAGUA|DEPARTAMENTO AMAZONAS	26,091	150.99	-6.000000	-79.000000	2
23	010202	010202 - ARAMANGO|BAGUA|DEPARTAMENTO AMAZONAS	10,940	809.07	-5.000000	-78.000000	2
24	010203	010203 - COPALLIN|BAGUA|DEPARTAMENTO AMAZONAS	6,319	99.05	-6.000000	-78.000000	2
25	010204	010204 - EL PARCO|BAGUA|DEPARTAMENTO AMAZONAS	1,492	18.48	-6.000000	-78.000000	2
26	010205	010205 - IMAZA|BAGUA|DEPARTAMENTO AMAZONAS	24,323	4,430.84	-5.000000	-78.000000	2
27	010206	010206 - LA PECA|BAGUA|DEPARTAMENTO AMAZONAS	8,048	144.29	-6.000000	-78.000000	2
28	010301	010301 - JUMBILLA|BONGARA|DEPARTAMENTO AMAZONAS	1,764	154.18	-6.000000	-78.000000	2
29	010302	010302 - CHISQUILLA|BONGARA|DEPARTAMENTO AMAZONAS	335	174.96	-6.000000	-78.000000	2
30	010303	010303 - CHURUJA|BONGARA|DEPARTAMENTO AMAZONAS	269	33.34	-6.000000	-78.000000	2
31	010304	010304 - COROSHA|BONGARA|DEPARTAMENTO AMAZONAS	1,046	45.67	-6.000000	-78.000000	2
32	010305	010305 - CUISPES|BONGARA|DEPARTAMENTO AMAZONAS	899	110.72	-6.000000	-78.000000	2
33	010306	010306 - FLORIDA|BONGARA|DEPARTAMENTO AMAZONAS	8,663	203.22	-6.000000	-78.000000	2
34	010307	010307 - JAZAN|BONGARA|DEPARTAMENTO AMAZONAS	9,349	88.83	-6.000000	-78.000000	2
35	010308	010308 - RECTA|BONGARA|DEPARTAMENTO AMAZONAS	201	24.58	-6.000000	-78.000000	2
36	010309	010309 - SAN CARLOS|BONGARA|DEPARTAMENTO AMAZONAS	310	100.76	-6.000000	-78.000000	2
37	010310	010310 - SHIPASBAMBA|BONGARA|DEPARTAMENTO AMAZONAS	1,819	127.29	-6.000000	-78.000000	2
38	010311	010311 - VALERA|BONGARA|DEPARTAMENTO AMAZONAS	1,281	90.14	-6.000000	-78.000000	2
39	010312	010312 - YAMBRASBAMBA|BONGARA|DEPARTAMENTO AMAZONAS	8,470	1,715.96	-6.000000	-78.000000	2
40	010401	010401 - NIEVA|CONDORCANQUI|DEPARTAMENTO AMAZONAS	29,213	4,481.63	-5.000000	-78.000000	2
41	010402	010402 - EL CENEPA|CONDORCANQUI|DEPARTAMENTO AMAZONAS	9,620	5,458.48	-4.000000	-78.000000	2
42	010403	010403 - RIO SANTIAGO|CONDORCANQUI|DEPARTAMENTO AMAZONAS	16,986	8,035.28	-4.000000	-78.000000	2
43	010501	010501 - LAMUD|LUYA|DEPARTAMENTO AMAZONAS	2,292	69.49	-6.000000	-78.000000	2
44	010502	010502 - CAMPORREDONDO|LUYA|DEPARTAMENTO AMAZONAS	7,131	376.01	-6.000000	-78.000000	2
45	010503	010503 - COCABAMBA|LUYA|DEPARTAMENTO AMAZONAS	2517	355.85	-7.000000	-78.000000	2
46	010504	010504 - COLCAMAR|LUYA|DEPARTAMENTO AMAZONAS	2,263	106.60	-6.000000	-78.000000	2
47	010505	010505 - CONILA|LUYA|DEPARTAMENTO AMAZONAS	2,083	256.17	-6.000000	-78.000000	2
48	010506	010506 - INGUILPATA|LUYA|DEPARTAMENTO AMAZONAS	587	118.04	-6.000000	-78.000000	2
49	010507	010507 - LONGUITA|LUYA|DEPARTAMENTO AMAZONAS	1,161	57.91	-6.000000	-78.000000	2
50	010508	010508 - LONYA CHICO|LUYA|DEPARTAMENTO AMAZONAS	961	83.82	-6.000000	-78.000000	2
51	010509	010509 - LUYA|LUYA|DEPARTAMENTO AMAZONAS	4,420	91.21	-6.000000	-78.000000	2
52	010510	010510 - LUYA VIEJO|LUYA|DEPARTAMENTO AMAZONAS	489	73.87	-6.000000	-78.000000	2
53	010511	010511 - MARIA|LUYA|DEPARTAMENTO AMAZONAS	945	80.27	-6.000000	-78.000000	2
54	010512	010512 - OCALLI|LUYA|DEPARTAMENTO AMAZONAS	4,259	177.39	-6.000000	-78.000000	2
55	010513	010513 - OCUMAL|LUYA|DEPARTAMENTO AMAZONAS	4,194	235.86	-6.000000	-78.000000	2
56	010514	010514 - PISUQUIA|LUYA|DEPARTAMENTO AMAZONAS	6,132	306.50	-7.000000	-78.000000	2
57	010515	010515 - PROVIDENCIA|LUYA|DEPARTAMENTO AMAZONAS	1,536	71.22	-6.000000	-78.000000	2
58	010516	010516 - SAN CRISTOBAL|LUYA|DEPARTAMENTO AMAZONAS	685	33.36	-6.000000	-78.000000	2
59	010517	010517 - SAN FRANCISCO DEL YESO|LUYA|DEPARTAMENTO AMAZONAS	821	113.94	-7.000000	-78.000000	2
60	010518	010518 - SAN JERONIMO|LUYA|DEPARTAMENTO AMAZONAS	880	214.66	-6.000000	-78.000000	2
61	010519	010519 - SAN JUAN DE LOPECANCHA|LUYA|DEPARTAMENTO AMAZONAS	506	88.02	-6.000000	-78.000000	2
62	010520	010520 - SANTA CATALINA|LUYA|DEPARTAMENTO AMAZONAS	1,908	126.21	-6.000000	-78.000000	2
63	010521	010521 - SANTO TOMAS|LUYA|DEPARTAMENTO AMAZONAS	3,537	84.93	-7.000000	-78.000000	2
64	010522	010522 - TINGO|LUYA|DEPARTAMENTO AMAZONAS	1,363	102.67	-6.000000	-78.000000	2
65	010523	010523 - TRITA|LUYA|DEPARTAMENTO AMAZONAS	1,378	12.68	-6.000000	-78.000000	2
66	010601	010601 - SAN NICOLAS|RODRIGUEZ DE MENDOZA|DEPARTAMENTO AMAZONAS	5,290	206.01	-6.000000	-77.000000	2
67	010602	010602 - CHIRIMOTO|RODRIGUEZ DE MENDOZA|DEPARTAMENTO AMAZONAS	2,079	153.00	-7.000000	-77.000000	2
68	010603	010603 - COCHAMAL|RODRIGUEZ DE MENDOZA|DEPARTAMENTO AMAZONAS	504	199.44	-6.000000	-78.000000	2
69	010604	010604 - HUAMBO|RODRIGUEZ DE MENDOZA|DEPARTAMENTO AMAZONAS	2,542	99.56	-6.000000	-78.000000	2
70	010605	010605 - LIMABAMBA|RODRIGUEZ DE MENDOZA|DEPARTAMENTO AMAZONAS	3,049	317.88	-7.000000	-78.000000	2
71	010606	010606 - LONGAR|RODRIGUEZ DE MENDOZA|DEPARTAMENTO AMAZONAS	1,619	66.24	-6.000000	-78.000000	2
72	010607	010607 - MARISCAL BENAVIDES|RODRIGUEZ DE MENDOZA|DEPARTAMENTO AMAZONAS	1,376	176.18	-6.000000	-78.000000	2
73	010608	010608 - MILPUC|RODRIGUEZ DE MENDOZA|DEPARTAMENTO AMAZONAS	599	26.80	-6.000000	-77.000000	2
74	010609	010609 - OMIA|RODRIGUEZ DE MENDOZA|DEPARTAMENTO AMAZONAS	9,787	175.13	-6.000000	-77.000000	2
75	010610	010610 - SANTA ROSA|RODRIGUEZ DE MENDOZA|DEPARTAMENTO AMAZONAS	457	34.11	-6.000000	-77.000000	2
76	010611	010611 - TOTORA|RODRIGUEZ DE MENDOZA|DEPARTAMENTO AMAZONAS	448	6.02	-6.000000	-77.000000	2
77	010612	010612 - VISTA ALEGRE|RODRIGUEZ DE MENDOZA|DEPARTAMENTO AMAZONAS	3,803	899.02	-6.000000	-77.000000	2
78	010701	010701 - BAGUA GRANDE|UTCUBAMBA|DEPARTAMENTO AMAZONAS	54,033	746.64	-6.000000	-78.000000	2
79	010702	010702 - CAJARURO|UTCUBAMBA|DEPARTAMENTO AMAZONAS	28,491	1,746.23	-6.000000	-78.000000	2
80	010703	010703 - CUMBA|UTCUBAMBA|DEPARTAMENTO AMAZONAS	8,752	292.66	-6.000000	-79.000000	2
81	010704	010704 - EL MILAGRO|UTCUBAMBA|DEPARTAMENTO AMAZONAS	6,399	313.89	-6.000000	-79.000000	2
82	010705	010705 - JAMALCA|UTCUBAMBA|DEPARTAMENTO AMAZONAS	8,243	357.98	-6.000000	-78.000000	2
83	010706	010706 - LONYA GRANDE|UTCUBAMBA|DEPARTAMENTO AMAZONAS	10,443	327.92	-6.000000	-78.000000	2
84	010707	010707 - YAMON|UTCUBAMBA|DEPARTAMENTO AMAZONAS	2,841	57.61	-6.000000	-79.000000	2
85	020101	020101 - HUARAZ|HUARAZ|DEPARTAMENTO ANCASH	65,663	432.99	-10.000000	-78.000000	2
86	020102	020102 - COCHABAMBA|HUARAZ|DEPARTAMENTO ANCASH	1,983	135.65	-9.000000	-78.000000	2
87	020103	020103 - COLCABAMBA|HUARAZ|DEPARTAMENTO ANCASH	826	50.65	-10.000000	-78.000000	2
88	020104	020104 - HUANCHAY|HUARAZ|DEPARTAMENTO ANCASH	2,235	209.34	-10.000000	-78.000000	2
89	020105	020105 - INDEPENDENCIA|HUARAZ|DEPARTAMENTO ANCASH	75,559	342.95	-10.000000	-78.000000	2
90	020106	020106 - JANGAS|HUARAZ|DEPARTAMENTO ANCASH	5,106	59.84	-9.000000	-78.000000	2
91	020107	020107 - LA LIBERTAD|HUARAZ|DEPARTAMENTO ANCASH	1,138	164.26	-10.000000	-78.000000	2
92	020108	020108 - OLLEROS|HUARAZ|DEPARTAMENTO ANCASH	2,148	222.91	-10.000000	-77.000000	2
93	020109	020109 - PAMPAS|HUARAZ|DEPARTAMENTO ANCASH	1,165	357.81	-10.000000	-78.000000	2
94	020110	020110 - PARIACOTO|HUARAZ|DEPARTAMENTO ANCASH	4,794	162.50	-10.000000	-78.000000	2
95	020111	020111 - PIRA|HUARAZ|DEPARTAMENTO ANCASH	3,755	243.73	-10.000000	-78.000000	2
96	020112	020112 - TARICA|HUARAZ|DEPARTAMENTO ANCASH	5,936	110.28	-9.000000	-78.000000	2
97	020201	020201 - AIJA|AIJA|DEPARTAMENTO ANCASH	1,841	159.74	-10.000000	-78.000000	2
98	020202	020202 - CORIS|AIJA|DEPARTAMENTO ANCASH	2,270	267.15	-10.000000	-78.000000	2
99	020203	020203 - HUACLLAN|AIJA|DEPARTAMENTO ANCASH	628	37.91	-10.000000	-78.000000	2
100	020204	020204 - LA MERCED|AIJA|DEPARTAMENTO ANCASH	2,190	153.08	-10.000000	-78.000000	2
101	020205	020205 - SUCCHA|AIJA|DEPARTAMENTO ANCASH	828	78.84	-10.000000	-78.000000	2
102	020301	020301 - LLAMELLIN|ANTONIO RAYMONDI|DEPARTAMENTO ANCASH	3,552	90.82	-9.000000	-77.000000	2
103	020302	020302 - ACZO|ANTONIO RAYMONDI|DEPARTAMENTO ANCASH	2,130	69.03	-9.000000	-77.000000	2
104	020303	020303 - CHACCHO|ANTONIO RAYMONDI|DEPARTAMENTO ANCASH	1,670	73.99	-9.000000	-77.000000	2
105	020304	020304 - CHINGAS|ANTONIO RAYMONDI|DEPARTAMENTO ANCASH	1,909	48.95	-9.000000	-77.000000	2
106	020305	020305 - MIRGAS|ANTONIO RAYMONDI|DEPARTAMENTO ANCASH	5,370	175.69	-9.000000	-77.000000	2
107	020306	020306 - SAN JUAN DE RONTOY|ANTONIO RAYMONDI|DEPARTAMENTO ANCASH	1,648	103.13	-9.000000	-77.000000	2
108	020401	020401 - CHACAS|ASUNCION|DEPARTAMENTO ANCASH	5,619	447.69	-9.000000	-77.000000	2
109	020402	020402 - ACOCHACA|ASUNCION|DEPARTAMENTO ANCASH	3,130	80.97	-9.000000	-77.000000	2
110	020501	020501 - CHIQUIAN|BOLOGNESI|DEPARTAMENTO ANCASH	3,587	184.16	-10.000000	-77.000000	2
111	020502	020502 - ABELARDO PARDO LEZAMETA|BOLOGNESI|DEPARTAMENTO ANCASH	1,263	11.31	-10.000000	-77.000000	2
112	020503	020503 - ANTONIO RAYMONDI|BOLOGNESI|DEPARTAMENTO ANCASH	1,065	118.70	-10.000000	-77.000000	2
113	020504	020504 - AQUIA|BOLOGNESI|DEPARTAMENTO ANCASH	2,465	434.60	-10.000000	-77.000000	2
114	020505	020505 - CAJACAY|BOLOGNESI|DEPARTAMENTO ANCASH	1,603	193.06	-10.000000	-77.000000	2
115	020506	020506 - CANIS|BOLOGNESI|DEPARTAMENTO ANCASH	1,308	19.45	-10.000000	-77.000000	2
116	020507	020507 - COLQUIOC|BOLOGNESI|DEPARTAMENTO ANCASH	4,165	274.61	-10.000000	-78.000000	2
117	020508	020508 - HUALLANCA|BOLOGNESI|DEPARTAMENTO ANCASH	8,325	873.39	-10.000000	-77.000000	2
118	020509	020509 - HUASTA|BOLOGNESI|DEPARTAMENTO ANCASH	2,610	387.91	-10.000000	-77.000000	2
119	020510	020510 - HUAYLLACAYAN|BOLOGNESI|DEPARTAMENTO ANCASH	1,076	127.99	-10.000000	-77.000000	2
120	020511	020511 - LA PRIMAVERA|BOLOGNESI|DEPARTAMENTO ANCASH	951	68.61	-10.000000	-77.000000	2
121	020512	020512 - MANGAS|BOLOGNESI|DEPARTAMENTO ANCASH	566	115.84	-10.000000	-77.000000	2
122	020513	020513 - PACLLON|BOLOGNESI|DEPARTAMENTO ANCASH	1,771	211.98	-10.000000	-77.000000	2
123	020514	020514 - SAN MIGUEL DE CORPANQUI|BOLOGNESI|DEPARTAMENTO ANCASH	1,298	43.78	-10.000000	-77.000000	2
124	020515	020515 - TICLLOS|BOLOGNESI|DEPARTAMENTO ANCASH	1,291	89.41	-10.000000	-77.000000	2
125	020601	020601 - CARHUAZ|CARHUAZ|DEPARTAMENTO ANCASH	15,712	194.62	-9.000000	-78.000000	2
126	020602	020602 - ACOPAMPA|CARHUAZ|DEPARTAMENTO ANCASH	2,685	14.17	-9.000000	-78.000000	2
127	020603	020603 - AMASHCA|CARHUAZ|DEPARTAMENTO ANCASH	1,571	11.99	-9.000000	-78.000000	2
128	020604	020604 - ANTA|CARHUAZ|DEPARTAMENTO ANCASH	2,510	40.77	-9.000000	-78.000000	2
129	020605	020605 - ATAQUERO|CARHUAZ|DEPARTAMENTO ANCASH	1,353	47.22	-9.000000	-78.000000	2
130	020606	020606 - MARCARA|CARHUAZ|DEPARTAMENTO ANCASH	9,452	157.49	-9.000000	-78.000000	2
131	020607	020607 - PARIAHUANCA|CARHUAZ|DEPARTAMENTO ANCASH	1,630	11.74	-9.000000	-78.000000	2
132	020608	020608 - SAN MIGUEL DE ACO|CARHUAZ|DEPARTAMENTO ANCASH	2,794	133.89	-9.000000	-78.000000	2
133	020609	020609 - SHILLA|CARHUAZ|DEPARTAMENTO ANCASH	3,318	130.19	-9.000000	-78.000000	2
134	020610	020610 - TINCO|CARHUAZ|DEPARTAMENTO ANCASH	3,301	15.44	-9.000000	-78.000000	2
135	020611	020611 - YUNGAR|CARHUAZ|DEPARTAMENTO ANCASH	3,462	46.43	-9.000000	-78.000000	2
136	020701	020701 - SAN LUIS|CARLOS FERMIN FITZCA|DEPARTAMENTO ANCASH	12,689	256.45	-9.000000	-77.000000	2
137	020702	020702 - SAN NICOLAS|CARLOS FERMIN FITZCA|DEPARTAMENTO ANCASH	3,690	197.39	-9.000000	-77.000000	2
138	020703	020703 - YAUYA|CARLOS FERMIN FITZCA|DEPARTAMENTO ANCASH	5,591	170.41	-9.000000	-77.000000	2
139	020801	020801 - CASMA|CASMA|DEPARTAMENTO ANCASH	33,648	1,204.85	-9.000000	-78.000000	2
140	020802	020802 - BUENA VISTA ALTA|CASMA|DEPARTAMENTO ANCASH	4,250	476.62	-9.000000	-78.000000	2
141	020803	020803 - COMANDANTE NOEL|CASMA|DEPARTAMENTO ANCASH	2,044	222.36	-9.000000	-78.000000	2
142	020804	020804 - YAUTAN|CASMA|DEPARTAMENTO ANCASH	8,531	357.20	-10.000000	-78.000000	2
143	020901	020901 - CORONGO|CORONGO|DEPARTAMENTO ANCASH	1,420	143.13	-9.000000	-78.000000	2
144	020902	020902 - ACO|CORONGO|DEPARTAMENTO ANCASH	442	56.54	-9.000000	-78.000000	2
145	020903	020903 - BAMBAS|CORONGO|DEPARTAMENTO ANCASH	546	151.13	-9.000000	-78.000000	2
146	020904	020904 - CUSCA|CORONGO|DEPARTAMENTO ANCASH	2,985	411.55	-9.000000	-78.000000	2
147	020905	020905 - LA PAMPA|CORONGO|DEPARTAMENTO ANCASH	1,004	93.94	-9.000000	-78.000000	2
148	020906	020906 - YANAC|CORONGO|DEPARTAMENTO ANCASH	704	45.85	-9.000000	-78.000000	2
149	020907	020907 - YUPAN|CORONGO|DEPARTAMENTO ANCASH	1,041	85.87	-9.000000	-78.000000	2
150	021001	021001 - HUARI|HUARI|DEPARTAMENTO ANCASH	10,423	398.91	-9.000000	-77.000000	2
151	021002	021002 - ANRA|HUARI|DEPARTAMENTO ANCASH	1,581	80.31	-9.000000	-77.000000	2
152	021003	021003 - CAJAY|HUARI|DEPARTAMENTO ANCASH	2,552	159.35	-9.000000	-77.000000	2
153	021004	021004 - CHAVIN DE HUANTAR|HUARI|DEPARTAMENTO ANCASH	9,251	434.13	-10.000000	-77.000000	2
154	021005	021005 - HUACACHI|HUARI|DEPARTAMENTO ANCASH	1,826	86.70	-9.000000	-77.000000	2
155	021006	021006 - HUACCHIS|HUARI|DEPARTAMENTO ANCASH	2,079	72.16	-9.000000	-77.000000	2
156	021007	021007 - HUACHIS|HUARI|DEPARTAMENTO ANCASH	3,466	153.89	-9.000000	-77.000000	2
157	021008	021008 - HUANTAR|HUARI|DEPARTAMENTO ANCASH	3,058	156.15	-9.000000	-77.000000	2
158	021009	021009 - MASIN|HUARI|DEPARTAMENTO ANCASH	1,652	75.33	-9.000000	-77.000000	2
159	021010	021010 - PAUCAS|HUARI|DEPARTAMENTO ANCASH	1,827	135.31	-9.000000	-77.000000	2
160	021011	021011 - PONTO|HUARI|DEPARTAMENTO ANCASH	3,333	118.29	-9.000000	-77.000000	2
161	021012	021012 - RAHUAPAMPA|HUARI|DEPARTAMENTO ANCASH	814	9.02	-9.000000	-77.000000	2
162	021013	021013 - RAPAYAN|HUARI|DEPARTAMENTO ANCASH	1,800	143.34	-9.000000	-77.000000	2
163	021014	021014 - SAN MARCOS|HUARI|DEPARTAMENTO ANCASH	15,094	556.75	-10.000000	-77.000000	2
164	021015	021015 - SAN PEDRO DE CHANA|HUARI|DEPARTAMENTO ANCASH	2,850	138.65	-9.000000	-77.000000	2
165	021016	021016 - UCO|HUARI|DEPARTAMENTO ANCASH	1,668	53.61	-9.000000	-77.000000	2
166	021101	021101 - HUARMEY|HUARMEY|DEPARTAMENTO ANCASH	24,856	2,894.38	-10.000000	-78.000000	2
167	021102	021102 - COCHAPETI|HUARMEY|DEPARTAMENTO ANCASH	747	100.02	-10.000000	-78.000000	2
168	021103	021103 - CULEBRAS|HUARMEY|DEPARTAMENTO ANCASH	3,758	630.25	-10.000000	-78.000000	2
169	021104	021104 - HUAYAN|HUARMEY|DEPARTAMENTO ANCASH	1,064	58.99	-10.000000	-78.000000	2
170	021105	021105 - MALVAS|HUARMEY|DEPARTAMENTO ANCASH	905	219.52	-10.000000	-78.000000	2
171	021201	021201 - CARAZ|HUAYLAS|DEPARTAMENTO ANCASH	26,740	246.52	-9.000000	-78.000000	2
172	021202	021202 - HUALLANCA|HUAYLAS|DEPARTAMENTO ANCASH	686	178.80	-9.000000	-78.000000	2
173	021203	021203 - HUATA|HUAYLAS|DEPARTAMENTO ANCASH	1,638	70.69	-9.000000	-78.000000	2
174	021204	021204 - HUAYLAS|HUAYLAS|DEPARTAMENTO ANCASH	1,421	56.89	-9.000000	-78.000000	2
175	021205	021205 - MATO|HUAYLAS|DEPARTAMENTO ANCASH	2,003	107.12	-9.000000	-78.000000	2
176	021206	021206 - PAMPAROMAS|HUAYLAS|DEPARTAMENTO ANCASH	9,268	496.35	-9.000000	-78.000000	2
177	021207	021207 - PUEBLO LIBRE|HUAYLAS|DEPARTAMENTO ANCASH	7,246	130.99	-9.000000	-78.000000	2
178	021208	021208 - SANTA CRUZ|HUAYLAS|DEPARTAMENTO ANCASH	5,236	357.70	-9.000000	-78.000000	2
179	021209	021209 - SANTO TORIBIO|HUAYLAS|DEPARTAMENTO ANCASH	1,056	82.02	-9.000000	-78.000000	2
180	021210	021210 - YURACMARCA|HUAYLAS|DEPARTAMENTO ANCASH	1,760	565.70	-9.000000	-78.000000	2
181	021301	021301 - PISCOBAMBA|MARISCAL LUZURIAGA|DEPARTAMENTO ANCASH	3,799	45.93	-9.000000	-77.000000	2
182	021302	021302 - CASCA|MARISCAL LUZURIAGA|DEPARTAMENTO ANCASH	4,534	77.38	-9.000000	-77.000000	2
183	021303	021303 - ELEAZAR GUZMAN BARRON|MARISCAL LUZURIAGA|DEPARTAMENTO ANCASH	1,381	93.96	-9.000000	-77.000000	2
184	021304	021304 - FIDEL OLIVAS ESCUDERO|MARISCAL LUZURIAGA|DEPARTAMENTO ANCASH	2,242	204.82	-9.000000	-77.000000	2
185	021305	021305 - LLAMA|MARISCAL LUZURIAGA|DEPARTAMENTO ANCASH	1,223	48.13	-9.000000	-77.000000	2
186	021306	021306 - LLUMPA|MARISCAL LUZURIAGA|DEPARTAMENTO ANCASH	6,435	143.27	-9.000000	-77.000000	2
187	021307	021307 - LUCMA|MARISCAL LUZURIAGA|DEPARTAMENTO ANCASH	3,262	77.37	-9.000000	-77.000000	2
188	021308	021308 - MUSGA|MARISCAL LUZURIAGA|DEPARTAMENTO ANCASH	1,014	39.72	-9.000000	-77.000000	2
189	021401	021401 - OCROS|OCROS|DEPARTAMENTO ANCASH	1,003	230.55	-10.000000	-77.000000	2
190	021402	021402 - ACAS|OCROS|DEPARTAMENTO ANCASH	1,057	252.48	-10.000000	-77.000000	2
191	021403	021403 - CAJAMARQUILLA|OCROS|DEPARTAMENTO ANCASH	600	75.52	-10.000000	-77.000000	2
192	021404	021404 - CARHUAPAMPA|OCROS|DEPARTAMENTO ANCASH	841	109.78	-10.000000	-77.000000	2
193	021405	021405 - COCHAS|OCROS|DEPARTAMENTO ANCASH	1,486	408.66	-11.000000	-77.000000	2
194	021406	021406 - CONGAS|OCROS|DEPARTAMENTO ANCASH	1,223	130.03	-10.000000	-77.000000	2
195	021407	021407 - LLIPA|OCROS|DEPARTAMENTO ANCASH	1,814	33.69	-10.000000	-77.000000	2
196	021408	021408 - SAN CRISTOBAL DE RAJAN|OCROS|DEPARTAMENTO ANCASH	639	67.75	-10.000000	-77.000000	2
197	021409	021409 - SAN PEDRO|OCROS|DEPARTAMENTO ANCASH	2,044	531.21	-10.000000	-77.000000	2
198	021410	021410 - SANTIAGO DE CHILCAS|OCROS|DEPARTAMENTO ANCASH	383	85.76	-10.000000	-77.000000	2
199	021501	021501 - CABANA|PALLASCA|DEPARTAMENTO ANCASH	2,715	150.29	-8.000000	-78.000000	2
200	021502	021502 - BOLOGNESI|PALLASCA|DEPARTAMENTO ANCASH	1,293	86.88	-8.000000	-78.000000	2
201	021503	021503 - CONCHUCOS|PALLASCA|DEPARTAMENTO ANCASH	8,458	585.24	-8.000000	-78.000000	2
202	021504	021504 - HUACASCHUQUE|PALLASCA|DEPARTAMENTO ANCASH	563	63.59	-8.000000	-78.000000	2
203	021505	021505 - HUANDOVAL|PALLASCA|DEPARTAMENTO ANCASH	1,123	116.00	-8.000000	-78.000000	2
204	021506	021506 - LACABAMBA|PALLASCA|DEPARTAMENTO ANCASH	559	64.68	-8.000000	-78.000000	2
205	021507	021507 - LLAPO|PALLASCA|DEPARTAMENTO ANCASH	732	28.69	-9.000000	-78.000000	2
206	021508	021508 - PALLASCA|PALLASCA|DEPARTAMENTO ANCASH	2,417	59.77	-8.000000	-78.000000	2
207	021509	021509 - PAMPAS|PALLASCA|DEPARTAMENTO ANCASH	8,780	438.18	-8.000000	-78.000000	2
208	021510	021510 - SANTA ROSA|PALLASCA|DEPARTAMENTO ANCASH	1,038	298.77	-9.000000	-78.000000	2
209	021511	021511 - TAUCA|PALLASCA|DEPARTAMENTO ANCASH	3,170	209.12	-8.000000	-78.000000	2
210	021601	021601 - POMABAMBA|POMABAMBA|DEPARTAMENTO ANCASH	16,631	347.92	-9.000000	-77.000000	2
211	021602	021602 - HUAYLLAN|POMABAMBA|DEPARTAMENTO ANCASH	3,668	88.97	-9.000000	-77.000000	2
212	021603	021603 - PAROBAMBA|POMABAMBA|DEPARTAMENTO ANCASH	7,016	331.10	-9.000000	-77.000000	2
213	021604	021604 - QUINUABAMBA|POMABAMBA|DEPARTAMENTO ANCASH	2,390	146.06	-9.000000	-77.000000	2
214	021701	021701 - RECUAY|RECUAY|DEPARTAMENTO ANCASH	4,372	142.96	-10.000000	-77.000000	2
215	021702	021702 - CATAC|RECUAY|DEPARTAMENTO ANCASH	4,038	1,018.27	-10.000000	-77.000000	2
216	021703	021703 - COTAPARACO|RECUAY|DEPARTAMENTO ANCASH	648	172.85	-10.000000	-78.000000	2
217	021704	021704 - HUAYLLAPAMPA|RECUAY|DEPARTAMENTO ANCASH	1,339	105.29	-10.000000	-78.000000	2
218	021705	021705 - LLACLLIN|RECUAY|DEPARTAMENTO ANCASH	1,872	101.10	-10.000000	-78.000000	2
219	021706	021706 - MARCA|RECUAY|DEPARTAMENTO ANCASH	969	184.84	-10.000000	-77.000000	2
220	021707	021707 - PAMPAS CHICO|RECUAY|DEPARTAMENTO ANCASH	2,109	100.51	-10.000000	-77.000000	2
221	021708	021708 - PARARIN|RECUAY|DEPARTAMENTO ANCASH	1,403	254.85	-10.000000	-78.000000	2
222	021709	021709 - TAPACOCHA|RECUAY|DEPARTAMENTO ANCASH	452	81.23	-10.000000	-78.000000	2
223	021710	021710 - TICAPAMPA|RECUAY|DEPARTAMENTO ANCASH	2,232	142.29	-10.000000	-77.000000	2
224	021801	021801 - CHIMBOTE|SANTA|DEPARTAMENTO ANCASH	216,037	1,461.44	-9.000000	-79.000000	2
225	021802	021802 - CACERES DEL PERU|SANTA|DEPARTAMENTO ANCASH	4,865	549.78	-9.000000	-78.000000	2
226	021803	021803 - COISHCO|SANTA|DEPARTAMENTO ANCASH	16,057	9.21	-9.000000	-79.000000	2
227	021804	021804 - MACATE|SANTA|DEPARTAMENTO ANCASH	3,325	584.65	-9.000000	-78.000000	2
228	021805	021805 - MORO|SANTA|DEPARTAMENTO ANCASH	7,545	359.35	-9.000000	-78.000000	2
229	021806	021806 - NEPEÑA|SANTA|DEPARTAMENTO ANCASH	15,949	458.24	-9.000000	-78.000000	2
230	021807	021807 - SAMANCO|SANTA|DEPARTAMENTO ANCASH	4,676	153.98	-9.000000	-79.000000	2
231	021808	021808 - SANTA|SANTA|DEPARTAMENTO ANCASH	21,041	42.23	-9.000000	-79.000000	2
232	021809	021809 - NUEVO CHIMBOTE|SANTA|DEPARTAMENTO ANCASH	157,211	389.73	-9.000000	-78.000000	2
233	021901	021901 - SIHUAS|SIHUAS|DEPARTAMENTO ANCASH	5,750	43.81	-9.000000	-78.000000	2
234	021902	021902 - ACOBAMBA|SIHUAS|DEPARTAMENTO ANCASH	2,234	153.04	-8.000000	-78.000000	2
235	021903	021903 - ALFONSO UGARTE|SIHUAS|DEPARTAMENTO ANCASH	762	80.71	-8.000000	-77.000000	2
236	021904	021904 - CASHAPAMPA|SIHUAS|DEPARTAMENTO ANCASH	2,833	66.96	-9.000000	-78.000000	2
237	021905	021905 - CHINGALPO|SIHUAS|DEPARTAMENTO ANCASH	1,034	173.20	-8.000000	-78.000000	2
238	021906	021906 - HUAYLLABAMBA|SIHUAS|DEPARTAMENTO ANCASH	3,982	287.58	-9.000000	-78.000000	2
239	021907	021907 - QUICHES|SIHUAS|DEPARTAMENTO ANCASH	2,958	146.98	-8.000000	-77.000000	2
240	021908	021908 - RAGASH|SIHUAS|DEPARTAMENTO ANCASH	2,613	208.45	-9.000000	-78.000000	2
241	021909	021909 - SAN JUAN|SIHUAS|DEPARTAMENTO ANCASH	6,568	209.24	-9.000000	-78.000000	2
242	021910	021910 - SICSIBAMBA|SIHUAS|DEPARTAMENTO ANCASH	1,808	86.00	-9.000000	-78.000000	2
243	022001	022001 - YUNGAY|YUNGAY|DEPARTAMENTO ANCASH	22323	276.68	-9.000000	-78.000000	2
244	022002	022002 - CASCAPARA|YUNGAY|DEPARTAMENTO ANCASH	2,332	138.32	-9.000000	-78.000000	2
245	022003	022003 - MANCOS|YUNGAY|DEPARTAMENTO ANCASH	6,954	64.05	-9.000000	-78.000000	2
246	022004	022004 - MATACOTO|YUNGAY|DEPARTAMENTO ANCASH	1,666	43.65	-9.000000	-78.000000	2
247	022005	022005 - QUILLO|YUNGAY|DEPARTAMENTO ANCASH	14,134	373.83	-9.000000	-78.000000	2
248	022006	022006 - RANRAHIRCA|YUNGAY|DEPARTAMENTO ANCASH	2,690	22.89	-9.000000	-78.000000	2
249	022007	022007 - SHUPLUY|YUNGAY|DEPARTAMENTO ANCASH	2,412	162.21	-9.000000	-78.000000	2
250	022008	022008 - YANAMA|YUNGAY|DEPARTAMENTO ANCASH	6,986	279.85	-9.000000	-77.000000	2
251	030101	030101 - ABANCAY|ABANCAY|DEPARTAMENTO APURIMAC	56,871	313.07	-14.000000	-73.000000	2
252	030102	030102 - CHACOCHE|ABANCAY|DEPARTAMENTO APURIMAC	1,226	186.10	-14.000000	-73.000000	2
253	030103	030103 - CIRCA|ABANCAY|DEPARTAMENTO APURIMAC	2,515	641.68	-14.000000	-73.000000	2
254	030104	030104 - CURAHUASI|ABANCAY|DEPARTAMENTO APURIMAC	18,422	817.98	-14.000000	-73.000000	2
255	030105	030105 - HUANIPACA|ABANCAY|DEPARTAMENTO APURIMAC	4,770	432.62	-13.000000	-73.000000	2
256	030106	030106 - LAMBRAMA|ABANCAY|DEPARTAMENTO APURIMAC	5,577	521.62	-14.000000	-73.000000	2
257	030107	030107 - PICHIRHUA|ABANCAY|DEPARTAMENTO APURIMAC	4,028	370.69	-14.000000	-73.000000	2
258	030108	030108 - SAN PEDRO DE CACHORA|ABANCAY|DEPARTAMENTO APURIMAC	3,864	108.77	-14.000000	-73.000000	2
259	030109	030109 - TAMBURCO|ABANCAY|DEPARTAMENTO APURIMAC	9,894	54.60	-14.000000	-73.000000	2
260	030201	030201 - ANDAHUAYLAS|ANDAHUAYLAS|DEPARTAMENTO APURIMAC	46,760	174.11	-14.000000	-73.000000	2
261	030202	030202 - ANDARAPA|ANDAHUAYLAS|DEPARTAMENTO APURIMAC	6,335	172.05	-14.000000	-73.000000	2
262	030203	030203 - CHIARA|ANDAHUAYLAS|DEPARTAMENTO APURIMAC	1,323	148.92	-14.000000	-74.000000	2
263	030204	030204 - HUANCARAMA|ANDAHUAYLAS|DEPARTAMENTO APURIMAC	7,408	153.04	-14.000000	-73.000000	2
264	030205	030205 - HUANCARAY|ANDAHUAYLAS|DEPARTAMENTO APURIMAC	4,617	112.20	-14.000000	-74.000000	2
265	030206	030206 - HUAYANA|ANDAHUAYLAS|DEPARTAMENTO APURIMAC	1,060	96.87	-14.000000	-74.000000	2
266	030207	030207 - KISHUARA|ANDAHUAYLAS|DEPARTAMENTO APURIMAC	9,356	309.91	-14.000000	-73.000000	2
267	030208	030208 - PACOBAMBA|ANDAHUAYLAS|DEPARTAMENTO APURIMAC	4,676	245.90	-14.000000	-73.000000	2
268	030209	030209 - PACUCHA|ANDAHUAYLAS|DEPARTAMENTO APURIMAC	9,833	170.39	-14.000000	-73.000000	2
269	030210	030210 - PAMPACHIRI|ANDAHUAYLAS|DEPARTAMENTO APURIMAC	2,820	602.50	-14.000000	-74.000000	2
270	030211	030211 - POMACOCHA|ANDAHUAYLAS|DEPARTAMENTO APURIMAC	1,048	129.19	-14.000000	-74.000000	2
271	030212	030212 - SAN ANTONIO DE CACHI|ANDAHUAYLAS|DEPARTAMENTO APURIMAC	3,183	178.78	-14.000000	-74.000000	2
272	030213	030213 - SAN JERONIMO|ANDAHUAYLAS|DEPARTAMENTO APURIMAC	29,017	237.42	-14.000000	-73.000000	2
273	030214	030214 - SAN MIGUEL DE CHACCRAMPA|ANDAHUAYLAS|DEPARTAMENTO APURIMAC	2,080	83.37	-14.000000	-74.000000	2
274	030215	030215 - SANTA MARIA DE CHICMO|ANDAHUAYLAS|DEPARTAMENTO APURIMAC	9,864	162.14	-14.000000	-73.000000	2
275	030216	030216 - TALAVERA|ANDAHUAYLAS|DEPARTAMENTO APURIMAC	18,478	148.12	-14.000000	-73.000000	2
276	030217	030217 - TUMAY HUARACA|ANDAHUAYLAS|DEPARTAMENTO APURIMAC	2,448	446.71	-14.000000	-74.000000	2
277	030218	030218 - TURPO|ANDAHUAYLAS|DEPARTAMENTO APURIMAC	4,152	121.67	-14.000000	-73.000000	2
278	030219	030219 - KAQUIABAMBA|ANDAHUAYLAS|DEPARTAMENTO APURIMAC	3,006	97.79	-14.000000	-73.000000	2
279	030220	030220 - JOSÉ MARÍA ARGUEDAS|ANDAHUAYLAS|DEPARTAMENTO APURIMAC	3,921	195.92	-14.000000	-73.000000	2
280	030301	030301 - ANTABAMBA|ANTABAMBA|DEPARTAMENTO APURIMAC	3,185	603.76	-14.000000	-73.000000	2
281	030302	030302 - EL ORO|ANTABAMBA|DEPARTAMENTO APURIMAC	548	68.81	-14.000000	-73.000000	2
282	030303	030303 - HUAQUIRCA|ANTABAMBA|DEPARTAMENTO APURIMAC	1,568	337.60	-14.000000	-73.000000	2
283	030304	030304 - JUAN ESPINOZA MEDRANO|ANTABAMBA|DEPARTAMENTO APURIMAC	2,044	623.22	-14.000000	-73.000000	2
284	030305	030305 - OROPESA|ANTABAMBA|DEPARTAMENTO APURIMAC	3,127	1,180.12	-14.000000	-73.000000	2
285	030306	030306 - PACHACONAS|ANTABAMBA|DEPARTAMENTO APURIMAC	1,291	226.73	-14.000000	-73.000000	2
286	030307	030307 - SABAINO|ANTABAMBA|DEPARTAMENTO APURIMAC	1,657	178.77	-14.000000	-73.000000	2
287	030401	030401 - CHALHUANCA|AYMARAES|DEPARTAMENTO APURIMAC	5,098	322.34	-14.000000	-73.000000	2
288	030402	030402 - CAPAYA|AYMARAES|DEPARTAMENTO APURIMAC	1,013	77.75	-14.000000	-73.000000	2
289	030403	030403 - CARAYBAMBA|AYMARAES|DEPARTAMENTO APURIMAC	1,497	234.91	-14.000000	-73.000000	2
290	030404	030404 - CHAPIMARCA|AYMARAES|DEPARTAMENTO APURIMAC	2,139	213.09	-14.000000	-73.000000	2
291	030405	030405 - COLCABAMBA|AYMARAES|DEPARTAMENTO APURIMAC	959	95.75	-14.000000	-73.000000	2
292	030406	030406 - COTARUSE|AYMARAES|DEPARTAMENTO APURIMAC	5,444	1,749.83	-14.000000	-73.000000	2
293	030407	030407 - HUAYLLO|AYMARAES|DEPARTAMENTO APURIMAC	739	72.89	-14.000000	-73.000000	2
294	030408	030408 - JUSTO APU SAHUARAURA|AYMARAES|DEPARTAMENTO APURIMAC	1,340	97.64	-14.000000	-73.000000	2
295	030409	030409 - LUCRE|AYMARAES|DEPARTAMENTO APURIMAC	2,141	110.48	-14.000000	-73.000000	2
296	030410	030410 - POCOHUANCA|AYMARAES|DEPARTAMENTO APURIMAC	1,154	82.55	-14.000000	-73.000000	2
297	030411	030411 - SAN JUAN DE CHACÑA|AYMARAES|DEPARTAMENTO APURIMAC	833	86.13	-14.000000	-73.000000	2
298	030412	030412 - SAÑAYCA|AYMARAES|DEPARTAMENTO APURIMAC	1,455	448.91	-14.000000	-73.000000	2
299	030413	030413 - SORAYA|AYMARAES|DEPARTAMENTO APURIMAC	824	43.56	-14.000000	-73.000000	2
300	030414	030414 - TAPAIRIHUA|AYMARAES|DEPARTAMENTO APURIMAC	2,252	163.73	-14.000000	-73.000000	2
301	030415	030415 - TINTAY|AYMARAES|DEPARTAMENTO APURIMAC	3,213	136.58	-14.000000	-73.000000	2
302	030416	030416 - TORAYA|AYMARAES|DEPARTAMENTO APURIMAC	2,005	173.05	-14.000000	-73.000000	2
303	030417	030417 - YANACA|AYMARAES|DEPARTAMENTO APURIMAC	1,169	103.88	-14.000000	-73.000000	2
304	030501	030501 - TAMBOBAMBA|COTABAMBAS|DEPARTAMENTO APURIMAC	11,793	722.23	-14.000000	-72.000000	2
305	030502	030502 - COTABAMBAS|COTABAMBAS|DEPARTAMENTO APURIMAC	4,274	331.96	-14.000000	-72.000000	2
306	030503	030503 - COYLLURQUI|COTABAMBAS|DEPARTAMENTO APURIMAC	8,629	418.95	-14.000000	-72.000000	2
307	030504	030504 - HAQUIRA|COTABAMBAS|DEPARTAMENTO APURIMAC	11,908	475.46	-14.000000	-72.000000	2
308	030505	030505 - MARA|COTABAMBAS|DEPARTAMENTO APURIMAC	6,718	224.17	-14.000000	-72.000000	2
309	030506	030506 - CHALLHUAHUACHO|COTABAMBAS|DEPARTAMENTO APURIMAC	9,998	439.96	-14.000000	-72.000000	2
310	030601	030601 - CHINCHEROS|CHINCHEROS|DEPARTAMENTO APURIMAC	6,998	132.40	-14.000000	-74.000000	2
311	030602	030602 - ANCO_HUALLO|CHINCHEROS|DEPARTAMENTO APURIMAC	12,627	38.90	-14.000000	-74.000000	2
312	030603	030603 - COCHARCAS|CHINCHEROS|DEPARTAMENTO APURIMAC	2,742	109.90	-14.000000	-74.000000	2
313	030604	030604 - HUACCANA|CHINCHEROS|DEPARTAMENTO APURIMAC	9,142	472.12	-13.000000	-74.000000	2
314	030605	030605 - OCOBAMBA|CHINCHEROS|DEPARTAMENTO APURIMAC	8,331	58.20	-13.000000	-74.000000	2
315	030606	030606 - ONGOY|CHINCHEROS|DEPARTAMENTO APURIMAC	3,812	118.69	-13.000000	-74.000000	2
316	030607	030607 - URANMARCA|CHINCHEROS|DEPARTAMENTO APURIMAC	3,748	148.73	-14.000000	-74.000000	2
317	030608	030608 - RANRACANCHA|CHINCHEROS|DEPARTAMENTO APURIMAC	5,377	44.52	-14.000000	-74.000000	2
318	030609	030609 - ROCCHACC|CHINCHEROS|DEPARTAMENTO APURIMAC	3,409	56.96	-13.000000	-74.000000	2
319	030610	030610 - EL PORVENIR|CHINCHEROS|DEPARTAMENTO APURIMAC	2,014	61.89	-13.000000	-74.000000	2
320	030611	030611 - LOS CHANKAS|CHINCHEROS|DEPARTAMENTO APURIMAC	1,276	142.22	-13.000000	-74.000000	2
321	030701	030701 - CHUQUIBAMBILLA|GRAU|DEPARTAMENTO APURIMAC	5,410	432.50	-14.000000	-73.000000	2
322	030702	030702 - CURPAHUASI|GRAU|DEPARTAMENTO APURIMAC	2,320	293.42	-14.000000	-73.000000	2
323	030703	030703 - GAMARRA|GRAU|DEPARTAMENTO APURIMAC	3,890	370.45	-14.000000	-73.000000	2
324	030704	030704 - HUAYLLATI|GRAU|DEPARTAMENTO APURIMAC	1,654	110.75	-14.000000	-72.000000	2
325	030705	030705 - MAMARA|GRAU|DEPARTAMENTO APURIMAC	973	66.21	-14.000000	-73.000000	2
326	030706	030706 - MICAELA BASTIDAS|GRAU|DEPARTAMENTO APURIMAC	1,689	110.14	-14.000000	-73.000000	2
327	030707	030707 - PATAYPAMPA|GRAU|DEPARTAMENTO APURIMAC	1,127	158.91	-14.000000	-73.000000	2
328	030708	030708 - PROGRESO|GRAU|DEPARTAMENTO APURIMAC	3,342	254.59	-14.000000	-72.000000	2
329	030709	030709 - SAN ANTONIO|GRAU|DEPARTAMENTO APURIMAC	358	24.12	-14.000000	-73.000000	2
330	030710	030710 - SANTA ROSA|GRAU|DEPARTAMENTO APURIMAC	700	36.16	-14.000000	-73.000000	2
331	030711	030711 - TURPAY|GRAU|DEPARTAMENTO APURIMAC	746	52.34	-14.000000	-73.000000	2
332	030712	030712 - VILCABAMBA|GRAU|DEPARTAMENTO APURIMAC	1,402	7.97	-14.000000	-73.000000	2
333	030713	030713 - VIRUNDO|GRAU|DEPARTAMENTO APURIMAC	1,305	117.19	-14.000000	-73.000000	2
334	030714	030714 - CURASCO|GRAU|DEPARTAMENTO APURIMAC	1,624	139.77	-14.000000	-73.000000	2
335	040101	040101 - AREQUIPA|AREQUIPA|DEPARTAMENTO AREQUIPA	52,425	2.80	-16.000000	-72.000000	2
336	040102	040102 - ALTO SELVA ALEGRE|AREQUIPA|DEPARTAMENTO AREQUIPA	85223	6.98	-16.000000	-72.000000	2
337	040103	040103 - CAYMA|AREQUIPA|DEPARTAMENTO AREQUIPA	96,878	246.31	-16.000000	-72.000000	2
338	040104	040104 - CERRO COLORADO|AREQUIPA|DEPARTAMENTO AREQUIPA	158836	174.90	-16.000000	-72.000000	2
339	040105	040105 - CHARACATO|AREQUIPA|DEPARTAMENTO AREQUIPA	10101	86.00	-16.000000	-71.000000	2
340	040106	040106 - CHIGUATA|AREQUIPA|DEPARTAMENTO AREQUIPA	3,012	460.81	-16.000000	-71.000000	2
341	040107	040107 - JACOBO HUNTER|AREQUIPA|DEPARTAMENTO AREQUIPA	48,985	20.37	-16.000000	-72.000000	2
342	040108	040108 - LA JOYA|AREQUIPA|DEPARTAMENTO AREQUIPA	32048	670.22	-16.000000	-72.000000	2
343	040109	040109 - MARIANO MELGAR|AREQUIPA|DEPARTAMENTO AREQUIPA	52,881	29.83	-16.000000	-72.000000	2
344	040110	040110 - MIRAFLORES|AREQUIPA|DEPARTAMENTO AREQUIPA	48,242	28.68	-16.000000	-72.000000	2
345	040111	040111 - MOLLEBAYA|AREQUIPA|DEPARTAMENTO AREQUIPA	1,979	26.70	-16.000000	-71.000000	2
346	040112	040112 - PAUCARPATA|AREQUIPA|DEPARTAMENTO AREQUIPA	126,053	31.07	-16.000000	-72.000000	2
347	040113	040113 - POCSI|AREQUIPA|DEPARTAMENTO AREQUIPA	537	172.48	-17.000000	-71.000000	2
348	040114	040114 - POLOBAYA|AREQUIPA|DEPARTAMENTO AREQUIPA	1,497	441.61	-17.000000	-71.000000	2
349	040115	040115 - QUEQUEÑA|AREQUIPA|DEPARTAMENTO AREQUIPA	1410	34.93	-17.000000	-71.000000	2
350	040116	040116 - SABANDIA|AREQUIPA|DEPARTAMENTO AREQUIPA	4234	36.63	-16.000000	-71.000000	2
351	040117	040117 - SACHACA|AREQUIPA|DEPARTAMENTO AREQUIPA	20,059	26.63	-16.000000	-72.000000	2
352	040118	040118 - SAN JUAN DE SIGUAS|AREQUIPA|DEPARTAMENTO AREQUIPA	1,591	93.31	-16.000000	-72.000000	2
353	040119	040119 - SAN JUAN DE TARUCANI|AREQUIPA|DEPARTAMENTO AREQUIPA	2,193	2,264.59	-16.000000	-71.000000	2
354	040120	040120 - SANTA ISABEL DE SIGUAS|AREQUIPA|DEPARTAMENTO AREQUIPA	1,273	187.98	-16.000000	-72.000000	2
355	040121	040121 - SANTA RITA DE SIGUAS|AREQUIPA|DEPARTAMENTO AREQUIPA	5,854	370.16	-16.000000	-72.000000	2
356	040122	040122 - SOCABAYA|AREQUIPA|DEPARTAMENTO AREQUIPA	83,799	18.64	-16.000000	-72.000000	2
357	040123	040123 - TIABAYA|AREQUIPA|DEPARTAMENTO AREQUIPA	14,812	31.62	-16.000000	-72.000000	2
358	040124	040124 - UCHUMAYO|AREQUIPA|DEPARTAMENTO AREQUIPA	12,950	227.14	-16.000000	-72.000000	2
359	040125	040125 - VITOR|AREQUIPA|DEPARTAMENTO AREQUIPA	2,267	1,543.50	-16.000000	-72.000000	2
360	040126	040126 - YANAHUARA|AREQUIPA|DEPARTAMENTO AREQUIPA	26,233	2.20	-16.000000	-72.000000	2
361	040127	040127 - YARABAMBA|AREQUIPA|DEPARTAMENTO AREQUIPA	1,140	492.20	-17.000000	-71.000000	2
362	040128	040128 - YURA|AREQUIPA|DEPARTAMENTO AREQUIPA	28,556	1,942.90	-16.000000	-72.000000	2
363	040129	040129 - JOSE LUIS BUSTAMANTE Y RIVERO|AREQUIPA|DEPARTAMENTO AREQUIPA	76,905	10.83	-16.000000	-72.000000	2
364	040201	040201 - CAMANA|CAMANA|DEPARTAMENTO AREQUIPA	14,409	11.67	-17.000000	-73.000000	2
365	040202	040202 - JOSE MARIA QUIMPER|CAMANA|DEPARTAMENTO AREQUIPA	4,195	16.72	-17.000000	-73.000000	2
366	040203	040203 - MARIANO NICOLAS VALCARCEL|CAMANA|DEPARTAMENTO AREQUIPA	7,728	557.74	-16.000000	-73.000000	2
367	040204	040204 - MARISCAL CACERES|CAMANA|DEPARTAMENTO AREQUIPA	6,637	579.31	-17.000000	-73.000000	2
368	040205	040205 - NICOLAS DE PIEROLA|CAMANA|DEPARTAMENTO AREQUIPA	6,387	391.84	-17.000000	-73.000000	2
369	040206	040206 - OCOÑA|CAMANA|DEPARTAMENTO AREQUIPA	4,862	1,414.80	-16.000000	-73.000000	2
370	040207	040207 - QUILCA|CAMANA|DEPARTAMENTO AREQUIPA	630	912.25	-17.000000	-72.000000	2
371	040208	040208 - SAMUEL PASTOR|CAMANA|DEPARTAMENTO AREQUIPA	15,933	113.40	-17.000000	-73.000000	2
372	040301	040301 - CARAVELI|CARAVELI|DEPARTAMENTO AREQUIPA	3,705	727.68	-16.000000	-73.000000	2
373	040302	040302 - ACARI|CARAVELI|DEPARTAMENTO AREQUIPA	3,010	799.21	-15.000000	-75.000000	2
374	040303	040303 - ATICO|CARAVELI|DEPARTAMENTO AREQUIPA	4,128	3,146.24	-16.000000	-74.000000	2
375	040304	040304 - ATIQUIPA|CARAVELI|DEPARTAMENTO AREQUIPA	945	423.55	-16.000000	-74.000000	2
376	040305	040305 - BELLA UNION|CARAVELI|DEPARTAMENTO AREQUIPA	7,296	1,588.41	-15.000000	-75.000000	2
377	040306	040306 - CAHUACHO|CARAVELI|DEPARTAMENTO AREQUIPA	909	1,412.10	-16.000000	-73.000000	2
378	040307	040307 - CHALA|CARAVELI|DEPARTAMENTO AREQUIPA	7,186	378.38	-16.000000	-74.000000	2
379	040308	040308 - CHAPARRA|CARAVELI|DEPARTAMENTO AREQUIPA	5,814	1,473.19	-16.000000	-74.000000	2
380	040309	040309 - HUANUHUANU|CARAVELI|DEPARTAMENTO AREQUIPA	3,469	708.52	-16.000000	-74.000000	2
381	040310	040310 - JAQUI|CARAVELI|DEPARTAMENTO AREQUIPA	1163	424.73	-15.000000	-74.000000	2
382	040311	040311 - LOMAS|CARAVELI|DEPARTAMENTO AREQUIPA	1,356	452.70	-16.000000	-75.000000	2
383	040312	040312 - QUICACHA|CARAVELI|DEPARTAMENTO AREQUIPA	1,890	1,048.42	-16.000000	-74.000000	2
384	040313	040313 - YAUCA|CARAVELI|DEPARTAMENTO AREQUIPA	1,555	556.30	-16.000000	-75.000000	2
385	040401	040401 - APLAO|CASTILLA|DEPARTAMENTO AREQUIPA	8,856	640.04	-16.000000	-72.000000	2
386	040402	040402 - ANDAGUA|CASTILLA|DEPARTAMENTO AREQUIPA	1,116	480.74	-15.000000	-72.000000	2
387	040403	040403 - AYO|CASTILLA|DEPARTAMENTO AREQUIPA	401	327.97	-16.000000	-72.000000	2
388	040404	040404 - CHACHAS|CASTILLA|DEPARTAMENTO AREQUIPA	1,671	1,190.49	-16.000000	-72.000000	2
389	040405	040405 - CHILCAYMARCA|CASTILLA|DEPARTAMENTO AREQUIPA	1,376	181.37	-15.000000	-72.000000	2
390	040406	040406 - CHOCO|CASTILLA|DEPARTAMENTO AREQUIPA	985	904.33	-16.000000	-72.000000	2
391	040407	040407 - HUANCARQUI|CASTILLA|DEPARTAMENTO AREQUIPA	1,288	803.65	-16.000000	-72.000000	2
392	040408	040408 - MACHAGUAY|CASTILLA|DEPARTAMENTO AREQUIPA	681	246.89	-16.000000	-73.000000	2
393	040409	040409 - ORCOPAMPA|CASTILLA|DEPARTAMENTO AREQUIPA	10,039	724.37	-15.000000	-72.000000	2
394	040410	040410 - PAMPACOLCA|CASTILLA|DEPARTAMENTO AREQUIPA	2,612	205.19	-16.000000	-73.000000	2
395	040411	040411 - TIPAN|CASTILLA|DEPARTAMENTO AREQUIPA	506	57.68	-16.000000	-73.000000	2
396	040412	040412 - UÑON|CASTILLA|DEPARTAMENTO AREQUIPA	464	296.93	-16.000000	-72.000000	2
397	040413	040413 - URACA|CASTILLA|DEPARTAMENTO AREQUIPA	7,235	713.83	-16.000000	-72.000000	2
398	040414	040414 - VIRACO|CASTILLA|DEPARTAMENTO AREQUIPA	1,647	141.00	-16.000000	-73.000000	2
399	040501	040501 - CHIVAY|CAYLLOMA|DEPARTAMENTO AREQUIPA	8,073	240.64	-16.000000	-72.000000	2
400	040502	040502 - ACHOMA|CAYLLOMA|DEPARTAMENTO AREQUIPA	869	393.54	-16.000000	-72.000000	2
401	040503	040503 - CABANACONDE|CAYLLOMA|DEPARTAMENTO AREQUIPA	2,332	460.55	-16.000000	-72.000000	2
402	040504	040504 - CALLALLI|CAYLLOMA|DEPARTAMENTO AREQUIPA	1,915	1,485.10	-16.000000	-71.000000	2
403	040505	040505 - CAYLLOMA|CAYLLOMA|DEPARTAMENTO AREQUIPA	3,021	1,499.00	-15.000000	-72.000000	2
404	040506	040506 - COPORAQUE|CAYLLOMA|DEPARTAMENTO AREQUIPA	1,542	111.98	-16.000000	-72.000000	2
405	040507	040507 - HUAMBO|CAYLLOMA|DEPARTAMENTO AREQUIPA	566	705.79	-16.000000	-72.000000	2
406	040508	040508 - HUANCA|CAYLLOMA|DEPARTAMENTO AREQUIPA	1,383	391.16	-16.000000	-72.000000	2
407	040509	040509 - ICHUPAMPA|CAYLLOMA|DEPARTAMENTO AREQUIPA	648	74.89	-16.000000	-72.000000	2
408	040510	040510 - LARI|CAYLLOMA|DEPARTAMENTO AREQUIPA	1,548	384.02	-16.000000	-72.000000	2
409	040511	040511 - LLUTA|CAYLLOMA|DEPARTAMENTO AREQUIPA	1,253	1,226.46	-16.000000	-72.000000	2
410	040512	040512 - MACA|CAYLLOMA|DEPARTAMENTO AREQUIPA	692	227.48	-16.000000	-72.000000	2
411	040513	040513 - MADRIGAL|CAYLLOMA|DEPARTAMENTO AREQUIPA	463	160.09	-16.000000	-72.000000	2
412	040514	040514 - SAN ANTONIO DE CHUCA|CAYLLOMA|DEPARTAMENTO AREQUIPA	1,593	1,531.27	-16.000000	-71.000000	2
413	040515	040515 - SIBAYO|CAYLLOMA|DEPARTAMENTO AREQUIPA	655	286.03	-15.000000	-71.000000	2
414	040516	040516 - TAPAY|CAYLLOMA|DEPARTAMENTO AREQUIPA	523	420.17	-16.000000	-72.000000	2
415	040517	040517 - TISCO|CAYLLOMA|DEPARTAMENTO AREQUIPA	1,388	1,445.02	-15.000000	-71.000000	2
416	040518	040518 - TUTI|CAYLLOMA|DEPARTAMENTO AREQUIPA	738	241.89	-16.000000	-72.000000	2
417	040519	040519 - YANQUE|CAYLLOMA|DEPARTAMENTO AREQUIPA	2,113	1,108.58	-16.000000	-72.000000	2
418	040520	040520 - MAJES|CAYLLOMA|DEPARTAMENTO AREQUIPA	69,348	1,625.80	-16.000000	-72.000000	2
419	040601	040601 - CHUQUIBAMBA|CONDESUYOS|DEPARTAMENTO AREQUIPA	3,279	1,255.04	-16.000000	-73.000000	2
420	040602	040602 - ANDARAY|CONDESUYOS|DEPARTAMENTO AREQUIPA	657	847.56	-16.000000	-73.000000	2
421	040603	040603 - CAYARANI|CONDESUYOS|DEPARTAMENTO AREQUIPA	3,046	1,395.67	-15.000000	-72.000000	2
422	040604	040604 - CHICHAS|CONDESUYOS|DEPARTAMENTO AREQUIPA	638	392.16	-16.000000	-73.000000	2
423	040605	040605 - IRAY|CONDESUYOS|DEPARTAMENTO AREQUIPA	633	247.62	-16.000000	-73.000000	2
424	040606	040606 - RIO GRANDE|CONDESUYOS|DEPARTAMENTO AREQUIPA	2,606	527.48	-16.000000	-73.000000	2
425	040607	040607 - SALAMANCA|CONDESUYOS|DEPARTAMENTO AREQUIPA	841	1,235.80	-16.000000	-73.000000	2
426	040608	040608 - YANAQUIHUA|CONDESUYOS|DEPARTAMENTO AREQUIPA	6,061	1,057.07	-16.000000	-73.000000	2
427	040701	040701 - MOLLENDO|ISLAY|DEPARTAMENTO AREQUIPA	22,008	960.83	-17.000000	-72.000000	2
428	040702	040702 - COCACHACRA|ISLAY|DEPARTAMENTO AREQUIPA	8,901	1,536.96	-17.000000	-72.000000	2
429	040703	040703 - DEAN VALDIVIA|ISLAY|DEPARTAMENTO AREQUIPA	6,703	134.08	-17.000000	-72.000000	2
430	040704	040704 - ISLAY|ISLAY|DEPARTAMENTO AREQUIPA	7,851	383.78	-17.000000	-72.000000	2
431	040705	040705 - MEJIA|ISLAY|DEPARTAMENTO AREQUIPA	1,014	100.78	-17.000000	-72.000000	2
432	040706	040706 - PUNTA DE BOMBON|ISLAY|DEPARTAMENTO AREQUIPA	6,444	769.60	-17.000000	-72.000000	2
433	040801	040801 - COTAHUASI|LA UNION|DEPARTAMENTO AREQUIPA	2,923	166.50	-15.000000	-73.000000	2
434	040802	040802 - ALCA|LA UNION|DEPARTAMENTO AREQUIPA	1,988	193.42	-15.000000	-73.000000	2
435	040803	040803 - CHARCANA|LA UNION|DEPARTAMENTO AREQUIPA	536	165.27	-15.000000	-73.000000	2
436	040804	040804 - HUAYNACOTAS|LA UNION|DEPARTAMENTO AREQUIPA	2,207	932.64	-15.000000	-73.000000	2
437	040805	040805 - PAMPAMARCA|LA UNION|DEPARTAMENTO AREQUIPA	1,231	782.17	-15.000000	-73.000000	2
438	040806	040806 - PUYCA|LA UNION|DEPARTAMENTO AREQUIPA	2,797	1,501.20	-15.000000	-73.000000	2
439	040807	040807 - QUECHUALLA|LA UNION|DEPARTAMENTO AREQUIPA	228	138.37	-15.000000	-73.000000	2
440	040808	040808 - SAYLA|LA UNION|DEPARTAMENTO AREQUIPA	592	66.55	-15.000000	-73.000000	2
441	040809	040809 - TAURIA|LA UNION|DEPARTAMENTO AREQUIPA	317	314.68	-15.000000	-73.000000	2
442	040810	040810 - TOMEPAMPA|LA UNION|DEPARTAMENTO AREQUIPA	813	94.16	-15.000000	-73.000000	2
443	040811	040811 - TORO|LA UNION|DEPARTAMENTO AREQUIPA	767	391.44	-15.000000	-73.000000	2
444	050101	050101 - AYACUCHO|HUAMANGA|DEPARTAMENTO AYACUCHO	96,671	83.11	-13.000000	-74.000000	2
445	050102	050102 - ACOCRO|HUAMANGA|DEPARTAMENTO AYACUCHO	11,081	436.65	-13.000000	-74.000000	2
446	050103	050103 - ACOS VINCHOS|HUAMANGA|DEPARTAMENTO AYACUCHO	6,197	156.82	-13.000000	-74.000000	2
447	050104	050104 - CARMEN ALTO|HUAMANGA|DEPARTAMENTO AYACUCHO	22,397	17.52	-13.000000	-74.000000	2
448	050105	050105 - CHIARA|HUAMANGA|DEPARTAMENTO AYACUCHO	7,216	461.61	-13.000000	-74.000000	2
449	050106	050106 - OCROS|HUAMANGA|DEPARTAMENTO AYACUCHO	6,466	305.41	-13.000000	-74.000000	2
450	050107	050107 - PACAYCASA|HUAMANGA|DEPARTAMENTO AYACUCHO	3,314	53.55	-13.000000	-74.000000	2
451	050108	050108 - QUINUA|HUAMANGA|DEPARTAMENTO AYACUCHO	6,375	116.61	-13.000000	-74.000000	2
452	050109	050109 - SAN JOSE DE TICLLAS|HUAMANGA|DEPARTAMENTO AYACUCHO	2,591	82.31	-13.000000	-74.000000	2
453	050110	050110 - SAN JUAN BAUTISTA|HUAMANGA|DEPARTAMENTO AYACUCHO	52,935	15.19	-13.000000	-74.000000	2
454	050111	050111 - SANTIAGO DE PISCHA|HUAMANGA|DEPARTAMENTO AYACUCHO	1,700	91.09	-13.000000	-74.000000	2
455	050112	050112 - SOCOS|HUAMANGA|DEPARTAMENTO AYACUCHO	7,637	172.34	-13.000000	-74.000000	2
456	050113	050113 - TAMBILLO|HUAMANGA|DEPARTAMENTO AYACUCHO	5,462	153.23	-13.000000	-74.000000	2
457	050114	050114 - VINCHOS|HUAMANGA|DEPARTAMENTO AYACUCHO	17,136	928.68	-13.000000	-74.000000	2
458	050115	050115 - JESUS NAZARENO|HUAMANGA|DEPARTAMENTO AYACUCHO	18,815	16.12	-13.000000	-74.000000	2
459	050116	050116 - ANDRÉS AVELINO CÁCERES DORREGARAY|HUAMANGA|DEPARTAMENTO AYACUCHO	22,356	9.28	-13.000000	-74.000000	2
460	050201	050201 - CANGALLO|CANGALLO|DEPARTAMENTO AYACUCHO	6,866	187.05	-14.000000	-74.000000	2
461	050202	050202 - CHUSCHI|CANGALLO|DEPARTAMENTO AYACUCHO	8,127	418.03	-14.000000	-74.000000	2
462	050203	050203 - LOS MOROCHUCOS|CANGALLO|DEPARTAMENTO AYACUCHO	8,316	253.22	-14.000000	-74.000000	2
463	050204	050204 - MARIA PARADO DE BELLIDO|CANGALLO|DEPARTAMENTO AYACUCHO	2,576	129.13	-14.000000	-74.000000	2
464	050205	050205 - PARAS|CANGALLO|DEPARTAMENTO AYACUCHO	4,636	789.09	-14.000000	-75.000000	2
465	050206	050206 - TOTOS|CANGALLO|DEPARTAMENTO AYACUCHO	3,742	112.90	-14.000000	-75.000000	2
466	050301	050301 - SANCOS|HUANCA SANCOS|DEPARTAMENTO AYACUCHO	3,632	1,289.70	-14.000000	-74.000000	2
467	050302	050302 - CARAPO|HUANCA SANCOS|DEPARTAMENTO AYACUCHO	2,543	241.34	-14.000000	-74.000000	2
468	050303	050303 - SACSAMARCA|HUANCA SANCOS|DEPARTAMENTO AYACUCHO	1,637	673.03	-14.000000	-74.000000	2
469	050304	050304 - SANTIAGO DE LUCANAMARCA|HUANCA SANCOS|DEPARTAMENTO AYACUCHO	2,683	658.26	-14.000000	-74.000000	2
470	050401	050401 - HUANTA|HUANTA|DEPARTAMENTO AYACUCHO	42,538	193.48	-13.000000	-74.000000	2
471	050402	050402 - AYAHUANCO|HUANTA|DEPARTAMENTO AYACUCHO	6,452	297.89	-13.000000	-74.000000	2
472	050403	050403 - HUAMANGUILLA|HUANTA|DEPARTAMENTO AYACUCHO	5,345	95.27	-13.000000	-74.000000	2
473	050404	050404 - IGUAIN|HUANTA|DEPARTAMENTO AYACUCHO	3327	61.44	-13.000000	-74.000000	2
474	050405	050405 - LURICOCHA|HUANTA|DEPARTAMENTO AYACUCHO	5,359	130.04	-13.000000	-74.000000	2
475	050406	050406 - SANTILLANA|HUANTA|DEPARTAMENTO AYACUCHO	4,906	336.17	-13.000000	-74.000000	2
476	050407	050407 - SIVIA|HUANTA|DEPARTAMENTO AYACUCHO	13,511	1,053.52	-13.000000	-74.000000	2
477	050408	050408 - LLOCHEGUA|HUANTA|DEPARTAMENTO AYACUCHO	11,372	469.02	-12.000000	-74.000000	2
478	050409	050409 - CANAYRE|HUANTA|DEPARTAMENTO AYACUCHO	3091	244.69	-12.000000	-74.000000	2
479	050410	050410 - UCHURACCAY|HUANTA|DEPARTAMENTO AYACUCHO	5,759	300.28	-13.000000	-74.000000	2
480	050411	050411 - PUCACOLPA|HUANTA|DEPARTAMENTO AYACUCHO	8,654	562.06	-15.000000	-73.000000	2
481	050412	050412 - CHACA|HUANTA|DEPARTAMENTO AYACUCHO	2,580	124.46	-13.000000	-74.000000	2
482	050501	050501 - SAN MIGUEL|LA MAR|DEPARTAMENTO AYACUCHO	9,248	457.88	-13.000000	-74.000000	2
483	050502	050502 - ANCO|LA MAR|DEPARTAMENTO AYACUCHO	11,144	802.86	-13.000000	-74.000000	2
484	050503	050503 - AYNA|LA MAR|DEPARTAMENTO AYACUCHO	10,559	290.51	-13.000000	-74.000000	2
485	050504	050504 - CHILCAS|LA MAR|DEPARTAMENTO AYACUCHO	3,081	156.58	-13.000000	-74.000000	2
486	050505	050505 - CHUNGUI|LA MAR|DEPARTAMENTO AYACUCHO	5,478	1,093.05	-13.000000	-74.000000	2
487	050506	050506 - LUIS CARRANZA|LA MAR|DEPARTAMENTO AYACUCHO	1,041	135.84	-13.000000	-74.000000	2
488	050507	050507 - SANTA ROSA|LA MAR|DEPARTAMENTO AYACUCHO	11,233	396.58	-13.000000	-74.000000	2
489	050508	050508 - TAMBO|LA MAR|DEPARTAMENTO AYACUCHO	20,429	313.82	-13.000000	-74.000000	2
490	050509	050509 - SAMUGARI|LA MAR|DEPARTAMENTO AYACUCHO	10,772	387.45	-13.000000	-74.000000	2
491	050510	050510 - ANCHIHUAY|LA MAR|DEPARTAMENTO AYACUCHO	5,640	272.07	-13.000000	-74.000000	2
492	050511	050511 - ORONCCOY|LA MAR|DEPARTAMENTO AYACUCHO	1,853	553.74	-13.000000	-73.000000	2
493	050601	050601 - PUQUIO|LUCANAS|DEPARTAMENTO AYACUCHO	14,166	866.43	-15.000000	-74.000000	2
494	050602	050602 - AUCARA|LUCANAS|DEPARTAMENTO AYACUCHO	5,640	903.51	-14.000000	-74.000000	2
495	050603	050603 - CABANA|LUCANAS|DEPARTAMENTO AYACUCHO	4,727	402.62	-14.000000	-74.000000	2
496	050604	050604 - CARMEN SALCEDO|LUCANAS|DEPARTAMENTO AYACUCHO	4,159	473.66	-14.000000	-74.000000	2
497	050605	050605 - CHAVIÑA|LUCANAS|DEPARTAMENTO AYACUCHO	2,025	399.09	-15.000000	-74.000000	2
498	050606	050606 - CHIPAO|LUCANAS|DEPARTAMENTO AYACUCHO	3,825	1,166.91	-14.000000	-74.000000	2
499	050607	050607 - HUAC-HUAS|LUCANAS|DEPARTAMENTO AYACUCHO	2,865	309.48	-14.000000	-75.000000	2
500	050608	050608 - LARAMATE|LUCANAS|DEPARTAMENTO AYACUCHO	1,367	785.89	-14.000000	-75.000000	2
501	050609	050609 - LEONCIO PRADO|LUCANAS|DEPARTAMENTO AYACUCHO	1,364	1,053.60	-15.000000	-75.000000	2
502	050610	050610 - LLAUTA|LUCANAS|DEPARTAMENTO AYACUCHO	1,126	482.07	-14.000000	-75.000000	2
503	050611	050611 - LUCANAS|LUCANAS|DEPARTAMENTO AYACUCHO	4,240	1,205.78	-15.000000	-74.000000	2
504	050612	050612 - OCAÑA|LUCANAS|DEPARTAMENTO AYACUCHO	2,932	848.36	-14.000000	-75.000000	2
505	050613	050613 - OTOCA|LUCANAS|DEPARTAMENTO AYACUCHO	3,149	720.20	-14.000000	-75.000000	2
506	050614	050614 - SAISA|LUCANAS|DEPARTAMENTO AYACUCHO	933	585.40	-15.000000	-74.000000	2
507	050615	050615 - SAN CRISTOBAL|LUCANAS|DEPARTAMENTO AYACUCHO	2,182	391.83	-15.000000	-74.000000	2
508	050616	050616 - SAN JUAN|LUCANAS|DEPARTAMENTO AYACUCHO	1,636	44.59	-15.000000	-74.000000	2
509	050617	050617 - SAN PEDRO|LUCANAS|DEPARTAMENTO AYACUCHO	3,019	733.03	-15.000000	-74.000000	2
510	050618	050618 - SAN PEDRO DE PALCO|LUCANAS|DEPARTAMENTO AYACUCHO	1,371	531.55	-14.000000	-75.000000	2
511	050619	050619 - SANCOS|LUCANAS|DEPARTAMENTO AYACUCHO	7,510	1,520.87	-15.000000	-74.000000	2
512	050620	050620 - SANTA ANA DE HUAYCAHUACHO|LUCANAS|DEPARTAMENTO AYACUCHO	669	50.63	-14.000000	-74.000000	2
513	050621	050621 - SANTA LUCIA|LUCANAS|DEPARTAMENTO AYACUCHO	889	1,019.14	-15.000000	-75.000000	2
514	050701	050701 - CORACORA|PARINACOCHAS|DEPARTAMENTO AYACUCHO	15,679	1,399.41	-15.000000	-74.000000	2
515	050702	050702 - CHUMPI|PARINACOCHAS|DEPARTAMENTO AYACUCHO	2,680	366.30	-15.000000	-74.000000	2
516	050703	050703 - CORONEL CASTAÑEDA|PARINACOCHAS|DEPARTAMENTO AYACUCHO	1,926	1,108.04	-15.000000	-73.000000	2
517	050704	050704 - PACAPAUSA|PARINACOCHAS|DEPARTAMENTO AYACUCHO	2,955	144.30	-15.000000	-73.000000	2
518	050705	050705 - PULLO|PARINACOCHAS|DEPARTAMENTO AYACUCHO	5,003	1,562.34	-15.000000	-74.000000	2
519	050706	050706 - PUYUSCA|PARINACOCHAS|DEPARTAMENTO AYACUCHO	2,091	700.75	-15.000000	-74.000000	2
520	050707	050707 - SAN FRANCISCO DE RAVACAYCO|PARINACOCHAS|DEPARTAMENTO AYACUCHO	770	99.83	-15.000000	-73.000000	2
521	050708	050708 - UPAHUACHO|PARINACOCHAS|DEPARTAMENTO AYACUCHO	2,817	587.35	-15.000000	-73.000000	2
522	050801	050801 - PAUSA|PAUCAR DEL SARA SARA|DEPARTAMENTO AYACUCHO	2,845	242.78	-15.000000	-73.000000	2
523	050802	050802 - COLTA|PAUCAR DEL SARA SARA|DEPARTAMENTO AYACUCHO	1179	277.29	-15.000000	-73.000000	2
524	050803	050803 - CORCULLA|PAUCAR DEL SARA SARA|DEPARTAMENTO AYACUCHO	445	97.05	-15.000000	-73.000000	2
525	050804	050804 - LAMPA|PAUCAR DEL SARA SARA|DEPARTAMENTO AYACUCHO	2,590	289.45	-15.000000	-73.000000	2
526	050805	050805 - MARCABAMBA|PAUCAR DEL SARA SARA|DEPARTAMENTO AYACUCHO	786	122.53	-15.000000	-73.000000	2
527	050806	050806 - OYOLO|PAUCAR DEL SARA SARA|DEPARTAMENTO AYACUCHO	1,226	820.13	-15.000000	-73.000000	2
528	050807	050807 - PARARCA|PAUCAR DEL SARA SARA|DEPARTAMENTO AYACUCHO	669	57.91	-15.000000	-73.000000	2
529	050808	050808 - SAN JAVIER DE ALPABAMBA|PAUCAR DEL SARA SARA|DEPARTAMENTO AYACUCHO	551	92.87	-15.000000	-73.000000	2
530	050809	050809 - SAN JOSE DE USHUA|PAUCAR DEL SARA SARA|DEPARTAMENTO AYACUCHO	179	17.33	-15.000000	-73.000000	2
531	050810	050810 - SARA SARA|PAUCAR DEL SARA SARA|DEPARTAMENTO AYACUCHO	735	79.58	-15.000000	-73.000000	2
532	050901	050901 - QUEROBAMBA|SUCRE|DEPARTAMENTO AYACUCHO	2792	275.65	-14.000000	-74.000000	2
533	050902	050902 - BELEN|SUCRE|DEPARTAMENTO AYACUCHO	772	41.46	-14.000000	-74.000000	2
534	050903	050903 - CHALCOS|SUCRE|DEPARTAMENTO AYACUCHO	635	58.43	-14.000000	-74.000000	2
535	050904	050904 - CHILCAYOC|SUCRE|DEPARTAMENTO AYACUCHO	568	33.06	-14.000000	-74.000000	2
536	050905	050905 - HUACAÑA|SUCRE|DEPARTAMENTO AYACUCHO	694	132.73	-14.000000	-74.000000	2
537	050906	050906 - MORCOLLA|SUCRE|DEPARTAMENTO AYACUCHO	1,035	289.34	-14.000000	-74.000000	2
538	050907	050907 - PAICO|SUCRE|DEPARTAMENTO AYACUCHO	837	79.65	-14.000000	-74.000000	2
539	050908	050908 - SAN PEDRO DE LARCAY|SUCRE|DEPARTAMENTO AYACUCHO	1,053	310.07	-14.000000	-74.000000	2
540	050909	050909 - SAN SALVADOR DE QUIJE|SUCRE|DEPARTAMENTO AYACUCHO	1,679	144.63	-14.000000	-74.000000	2
541	050910	050910 - SANTIAGO DE PAUCARAY|SUCRE|DEPARTAMENTO AYACUCHO	724	62.65	-14.000000	-74.000000	2
542	050911	050911 - SORAS|SUCRE|DEPARTAMENTO AYACUCHO	1,331	357.97	-14.000000	-74.000000	2
543	051001	051001 - HUANCAPI|VICTOR FAJARDO|DEPARTAMENTO AYACUCHO	2,049	223.35	-14.000000	-74.000000	2
544	051002	051002 - ALCAMENCA|VICTOR FAJARDO|DEPARTAMENTO AYACUCHO	2,428	125.11	-14.000000	-74.000000	2
545	051003	051003 - APONGO|VICTOR FAJARDO|DEPARTAMENTO AYACUCHO	1,420	171.58	-14.000000	-74.000000	2
546	051004	051004 - ASQUIPATA|VICTOR FAJARDO|DEPARTAMENTO AYACUCHO	444	70.72	-14.000000	-74.000000	2
547	051005	051005 - CANARIA|VICTOR FAJARDO|DEPARTAMENTO AYACUCHO	4,057	263.88	-14.000000	-74.000000	2
548	051006	051006 - CAYARA|VICTOR FAJARDO|DEPARTAMENTO AYACUCHO	1,204	69.25	-14.000000	-74.000000	2
549	051007	051007 - COLCA|VICTOR FAJARDO|DEPARTAMENTO AYACUCHO	1,078	69.57	-14.000000	-74.000000	2
550	051008	051008 - HUAMANQUIQUIA|VICTOR FAJARDO|DEPARTAMENTO AYACUCHO	1,248	67.33	-14.000000	-74.000000	2
551	051009	051009 - HUANCARAYLLA|VICTOR FAJARDO|DEPARTAMENTO AYACUCHO	1,207	165.49	-14.000000	-74.000000	2
552	051010	051010 - HUAYA|VICTOR FAJARDO|DEPARTAMENTO AYACUCHO	3,284	162.23	-14.000000	-74.000000	2
553	051011	051011 - SARHUA|VICTOR FAJARDO|DEPARTAMENTO AYACUCHO	2,778	373.14	-14.000000	-74.000000	2
554	051012	051012 - VILCANCHOS|VICTOR FAJARDO|DEPARTAMENTO AYACUCHO	2,733	498.54	-14.000000	-75.000000	2
555	051101	051101 - VILCAS HUAMAN|VILCAS HUAMAN|DEPARTAMENTO AYACUCHO	8,545	216.89	-14.000000	-74.000000	2
556	051102	051102 - ACCOMARCA|VILCAS HUAMAN|DEPARTAMENTO AYACUCHO	939	82.43	-14.000000	-74.000000	2
557	051103	051103 - CARHUANCA|VILCAS HUAMAN|DEPARTAMENTO AYACUCHO	1,018	56.91	-14.000000	-74.000000	2
558	051104	051104 - CONCEPCION|VILCAS HUAMAN|DEPARTAMENTO AYACUCHO	3,193	215.03	-14.000000	-74.000000	2
559	051105	051105 - HUAMBALPA|VILCAS HUAMAN|DEPARTAMENTO AYACUCHO	2,206	150.76	-14.000000	-74.000000	2
560	051106	051106 - INDEPENDENCIA|VILCAS HUAMAN|DEPARTAMENTO AYACUCHO	1,583	85.28	-14.000000	-74.000000	2
561	051107	051107 - SAURAMA|VILCAS HUAMAN|DEPARTAMENTO AYACUCHO	1,297	95.15	-14.000000	-74.000000	2
562	051108	051108 - VISCHONGO|VILCAS HUAMAN|DEPARTAMENTO AYACUCHO	4,828	268.87	-14.000000	-74.000000	2
563	060101	060101 - CAJAMARCA|CAJAMARCA|DEPARTAMENTO CAJAMARCA	251,097	382.74	-7.000000	-79.000000	2
564	060102	060102 - ASUNCION|CAJAMARCA|DEPARTAMENTO CAJAMARCA	13,508	210.18	-7.000000	-79.000000	2
565	060103	060103 - CHETILLA|CAJAMARCA|DEPARTAMENTO CAJAMARCA	4,319	73.94	-7.000000	-79.000000	2
566	060104	060104 - COSPAN|CAJAMARCA|DEPARTAMENTO CAJAMARCA	7,882	558.79	-7.000000	-79.000000	2
567	060105	060105 - ENCAÑADA|CAJAMARCA|DEPARTAMENTO CAJAMARCA	24,290	635.06	-7.000000	-78.000000	2
568	060106	060106 - JESUS|CAJAMARCA|DEPARTAMENTO CAJAMARCA	14,742	267.78	-7.000000	-78.000000	2
569	060107	060107 - LLACANORA|CAJAMARCA|DEPARTAMENTO CAJAMARCA	5,404	49.42	-7.000000	-78.000000	2
570	060108	060108 - LOS BAÑOS DEL INCA|CAJAMARCA|DEPARTAMENTO CAJAMARCA	43,401	276.40	-7.000000	-78.000000	2
571	060109	060109 - MAGDALENA|CAJAMARCA|DEPARTAMENTO CAJAMARCA	9,689	215.38	-7.000000	-79.000000	2
572	060110	060110 - MATARA|CAJAMARCA|DEPARTAMENTO CAJAMARCA	3,542	59.74	-7.000000	-78.000000	2
573	060111	060111 - NAMORA|CAJAMARCA|DEPARTAMENTO CAJAMARCA	10,740	180.69	-7.000000	-78.000000	2
574	060112	060112 - SAN JUAN|CAJAMARCA|DEPARTAMENTO CAJAMARCA	5,232	69.66	-7.000000	-78.000000	2
575	060201	060201 - CAJABAMBA|CAJABAMBA|DEPARTAMENTO CAJAMARCA	30,798	192.29	-8.000000	-78.000000	2
576	060202	060202 - CACHACHI|CAJABAMBA|DEPARTAMENTO CAJAMARCA	26,990	820.81	-7.000000	-78.000000	2
577	060203	060203 - CONDEBAMBA|CAJABAMBA|DEPARTAMENTO CAJAMARCA	14,006	204.60	-8.000000	-78.000000	2
578	060204	060204 - SITACOCHA|CAJABAMBA|DEPARTAMENTO CAJAMARCA	8,910	589.94	-8.000000	-78.000000	2
579	060301	060301 - CELENDIN|CELENDIN|DEPARTAMENTO CAJAMARCA	28,319	409.00	-7.000000	-78.000000	2
580	060302	060302 - CHUMUCH|CELENDIN|DEPARTAMENTO CAJAMARCA	3,198	196.30	-7.000000	-78.000000	2
581	060303	060303 - CORTEGANA|CELENDIN|DEPARTAMENTO CAJAMARCA	8,878	233.31	-6.000000	-78.000000	2
582	060304	060304 - HUASMIN|CELENDIN|DEPARTAMENTO CAJAMARCA	13,621	437.50	-7.000000	-78.000000	2
583	060305	060305 - JORGE CHAVEZ|CELENDIN|DEPARTAMENTO CAJAMARCA	593	53.34	-7.000000	-78.000000	2
584	060306	060306 - JOSE GALVEZ|CELENDIN|DEPARTAMENTO CAJAMARCA	2,497	58.01	-7.000000	-78.000000	2
585	060307	060307 - MIGUEL IGLESIAS|CELENDIN|DEPARTAMENTO CAJAMARCA	5,613	235.73	-7.000000	-78.000000	2
586	060308	060308 - OXAMARCA|CELENDIN|DEPARTAMENTO CAJAMARCA	6,977	292.52	-7.000000	-78.000000	2
587	060309	060309 - SOROCHUCO|CELENDIN|DEPARTAMENTO CAJAMARCA	9,881	170.02	-7.000000	-78.000000	2
588	060310	060310 - SUCRE|CELENDIN|DEPARTAMENTO CAJAMARCA	6,085	270.98	-7.000000	-78.000000	2
589	060311	060311 - UTCO|CELENDIN|DEPARTAMENTO CAJAMARCA	1,417	100.79	-7.000000	-78.000000	2
590	060312	060312 - LA LIBERTAD DE PALLAN|CELENDIN|DEPARTAMENTO CAJAMARCA	9,101	184.09	-7.000000	-78.000000	2
591	060401	060401 - CHOTA|CHOTA|DEPARTAMENTO CAJAMARCA	48,903	261.75	-7.000000	-79.000000	2
592	060402	060402 - ANGUIA|CHOTA|DEPARTAMENTO CAJAMARCA	4,296	123.01	-6.000000	-79.000000	2
593	060403	060403 - CHADIN|CHOTA|DEPARTAMENTO CAJAMARCA	4,104	66.53	-6.000000	-78.000000	2
594	060404	060404 - CHIGUIRIP|CHOTA|DEPARTAMENTO CAJAMARCA	4,663	51.44	-6.000000	-79.000000	2
595	060405	060405 - CHIMBAN|CHOTA|DEPARTAMENTO CAJAMARCA	3,684	198.99	-6.000000	-78.000000	2
596	060406	060406 - CHOROPAMPA|CHOTA|DEPARTAMENTO CAJAMARCA	2,553	171.59	-6.000000	-78.000000	2
597	060407	060407 - COCHABAMBA|CHOTA|DEPARTAMENTO CAJAMARCA	6,401	130.01	-6.000000	-79.000000	2
598	060408	060408 - CONCHAN|CHOTA|DEPARTAMENTO CAJAMARCA	7,060	180.23	-6.000000	-79.000000	2
599	060409	060409 - HUAMBOS|CHOTA|DEPARTAMENTO CAJAMARCA	9,490	240.72	-6.000000	-79.000000	2
600	060410	060410 - LAJAS|CHOTA|DEPARTAMENTO CAJAMARCA	12,505	120.73	-7.000000	-79.000000	2
601	060411	060411 - LLAMA|CHOTA|DEPARTAMENTO CAJAMARCA	8,037	494.94	-7.000000	-79.000000	2
602	060412	060412 - MIRACOSTA|CHOTA|DEPARTAMENTO CAJAMARCA	3,924	415.69	-6.000000	-79.000000	2
603	060413	060413 - PACCHA|CHOTA|DEPARTAMENTO CAJAMARCA	5,335	93.97	-7.000000	-78.000000	2
604	060414	060414 - PION|CHOTA|DEPARTAMENTO CAJAMARCA	1,566	141.05	-6.000000	-78.000000	2
605	060415	060415 - QUEROCOTO|CHOTA|DEPARTAMENTO CAJAMARCA	8,918	301.07	-6.000000	-79.000000	2
606	060416	060416 - SAN JUAN DE LICUPIS|CHOTA|DEPARTAMENTO CAJAMARCA	969	205.01	-6.000000	-79.000000	2
607	060417	060417 - TACABAMBA|CHOTA|DEPARTAMENTO CAJAMARCA	20,132	196.25	-6.000000	-79.000000	2
608	060418	060418 - TOCMOCHE|CHOTA|DEPARTAMENTO CAJAMARCA	993	222.38	-6.000000	-79.000000	2
609	060419	060419 - CHALAMARCA|CHOTA|DEPARTAMENTO CAJAMARCA	11,274	179.74	-6.000000	-78.000000	2
610	060501	060501 - CONTUMAZA|CONTUMAZA|DEPARTAMENTO CAJAMARCA	8,461	358.28	-7.000000	-79.000000	2
611	060502	060502 - CHILETE|CONTUMAZA|DEPARTAMENTO CAJAMARCA	2,733	133.94	-7.000000	-79.000000	2
612	060503	060503 - CUPISNIQUE|CONTUMAZA|DEPARTAMENTO CAJAMARCA	1,457	280.20	-7.000000	-79.000000	2
613	060504	060504 - GUZMANGO|CONTUMAZA|DEPARTAMENTO CAJAMARCA	3,146	49.88	-7.000000	-79.000000	2
614	060505	060505 - SAN BENITO|CONTUMAZA|DEPARTAMENTO CAJAMARCA	3,845	486.55	-7.000000	-79.000000	2
615	060506	060506 - SANTA CRUZ DE TOLED|CONTUMAZA|DEPARTAMENTO CAJAMARCA	1,044	64.53	-7.000000	-79.000000	2
616	060507	060507 - TANTARICA|CONTUMAZA|DEPARTAMENTO CAJAMARCA	3,303	149.70	-7.000000	-79.000000	2
617	060508	060508 - YONAN|CONTUMAZA|DEPARTAMENTO CAJAMARCA	7,907	547.25	-7.000000	-79.000000	2
618	060601	060601 - CUTERVO|CUTERVO|DEPARTAMENTO CAJAMARCA	56,382	422.27	-6.000000	-79.000000	2
619	060602	060602 - CALLAYUC|CUTERVO|DEPARTAMENTO CAJAMARCA	10,280	316.05	-6.000000	-79.000000	2
620	060603	060603 - CHOROS|CUTERVO|DEPARTAMENTO CAJAMARCA	3,595	276.96	-6.000000	-79.000000	2
621	060604	060604 - CUJILLO|CUTERVO|DEPARTAMENTO CAJAMARCA	3,040	108.93	-6.000000	-79.000000	2
622	060605	060605 - LA RAMADA|CUTERVO|DEPARTAMENTO CAJAMARCA	4862	30.27	-6.000000	-79.000000	2
623	060606	060606 - PIMPINGOS|CUTERVO|DEPARTAMENTO CAJAMARCA	5,697	186.04	-6.000000	-79.000000	2
624	060607	060607 - QUEROCOTILLO|CUTERVO|DEPARTAMENTO CAJAMARCA	17,001	697.10	-6.000000	-79.000000	2
625	060608	060608 - SAN ANDRES DE CUTERVO|CUTERVO|DEPARTAMENTO CAJAMARCA	5,240	133.40	-6.000000	-79.000000	2
626	060609	060609 - SAN JUAN DE CUTERVO|CUTERVO|DEPARTAMENTO CAJAMARCA	1,981	60.87	-6.000000	-79.000000	2
627	060610	060610 - SAN LUIS DE LUCMA|CUTERVO|DEPARTAMENTO CAJAMARCA	4,042	109.74	-6.000000	-79.000000	2
628	060611	060611 - SANTA CRUZ|CUTERVO|DEPARTAMENTO CAJAMARCA	2,889	128.00	-6.000000	-79.000000	2
629	060612	060612 - SANTO DOMINGO DE LA CAPILLA|CUTERVO|DEPARTAMENTO CAJAMARCA	5,649	103.74	-6.000000	-79.000000	2
630	060613	060613 - SANTO TOMAS|CUTERVO|DEPARTAMENTO CAJAMARCA	7,931	279.61	-6.000000	-79.000000	2
631	060614	060614 - SOCOTA|CUTERVO|DEPARTAMENTO CAJAMARCA	10,720	134.83	-6.000000	-79.000000	2
632	060615	060615 - TORIBIO CASANOVA|CUTERVO|DEPARTAMENTO CAJAMARCA	1,262	40.65	-6.000000	-79.000000	2
633	060701	060701 - BAMBAMARCA|HUALGAYOC|DEPARTAMENTO CAJAMARCA	82744	451.38	-7.000000	-79.000000	2
634	060702	060702 - CHUGUR|HUALGAYOC|DEPARTAMENTO CAJAMARCA	3,601	99.60	-7.000000	-79.000000	2
635	060703	060703 - HUALGAYOC|HUALGAYOC|DEPARTAMENTO CAJAMARCA	16,979	226.17	-7.000000	-79.000000	2
636	060801	060801 - JAEN|JAEN|DEPARTAMENTO CAJAMARCA	101,726	537.25	-6.000000	-79.000000	2
637	060802	060802 - BELLAVISTA|JAEN|DEPARTAMENTO CAJAMARCA	15,310	870.55	-6.000000	-79.000000	2
638	060803	060803 - CHONTALI|JAEN|DEPARTAMENTO CAJAMARCA	10,232	428.55	-6.000000	-79.000000	2
639	060804	060804 - COLASAY|JAEN|DEPARTAMENTO CAJAMARCA	10,447	735.73	-6.000000	-79.000000	2
640	060805	060805 - HUABAL|JAEN|DEPARTAMENTO CAJAMARCA	6,956	80.69	-6.000000	-79.000000	2
641	060806	060806 - LAS PIRIAS|JAEN|DEPARTAMENTO CAJAMARCA	4,009	60.41	-6.000000	-79.000000	2
642	060807	060807 - POMAHUACA|JAEN|DEPARTAMENTO CAJAMARCA	10,190	732.80	-6.000000	-79.000000	2
643	060808	060808 - PUCARA|JAEN|DEPARTAMENTO CAJAMARCA	7,703	240.30	-6.000000	-79.000000	2
644	060809	060809 - SALLIQUE|JAEN|DEPARTAMENTO CAJAMARCA	8,730	373.89	-6.000000	-79.000000	2
645	060810	060810 - SAN FELIPE|JAEN|DEPARTAMENTO CAJAMARCA	6,266	255.49	-6.000000	-79.000000	2
646	060811	060811 - SAN JOSE DEL ALTO|JAEN|DEPARTAMENTO CAJAMARCA	7,209	634.11	-5.000000	-79.000000	2
647	060812	060812 - SANTA ROSA|JAEN|DEPARTAMENTO CAJAMARCA	11,363	282.80	-5.000000	-79.000000	2
648	060901	060901 - SAN IGNACIO|SAN IGNACIO|DEPARTAMENTO CAJAMARCA	37,862	381.88	-5.000000	-79.000000	2
649	060902	060902 - CHIRINOS|SAN IGNACIO|DEPARTAMENTO CAJAMARCA	14,355	351.91	-5.000000	-79.000000	2
650	060903	060903 - HUARANGO|SAN IGNACIO|DEPARTAMENTO CAJAMARCA	20,589	922.35	-5.000000	-79.000000	2
651	060904	060904 - LA COIPA|SAN IGNACIO|DEPARTAMENTO CAJAMARCA	21,056	376.09	-5.000000	-79.000000	2
652	060905	060905 - NAMBALLE|SAN IGNACIO|DEPARTAMENTO CAJAMARCA	11,717	663.51	-5.000000	-79.000000	2
653	060906	060906 - SAN JOSE DE LOURDES|SAN IGNACIO|DEPARTAMENTO CAJAMARCA	22,147	1,482.75	-5.000000	-79.000000	2
654	060907	060907 - TABACONAS|SAN IGNACIO|DEPARTAMENTO CAJAMARCA	22,002	798.59	-5.000000	-79.000000	2
655	061001	061001 - PEDRO GALVEZ|SAN MARCOS|DEPARTAMENTO CAJAMARCA	21,549	238.74	-7.000000	-78.000000	2
656	061002	061002 - CHANCAY|SAN MARCOS|DEPARTAMENTO CAJAMARCA	3,337	61.80	-7.000000	-78.000000	2
657	061003	061003 - EDUARDO VILLANUEVA|SAN MARCOS|DEPARTAMENTO CAJAMARCA	2,288	63.13	-7.000000	-78.000000	2
658	061004	061004 - GREGORIO PITA|SAN MARCOS|DEPARTAMENTO CAJAMARCA	6,666	212.81	-7.000000	-78.000000	2
659	061005	061005 - ICHOCAN|SAN MARCOS|DEPARTAMENTO CAJAMARCA	1,624	76.11	-7.000000	-78.000000	2
660	061006	061006 - JOSE MANUEL QUIROZ|SAN MARCOS|DEPARTAMENTO CAJAMARCA	3,961	115.42	-7.000000	-78.000000	2
661	061007	061007 - JOSE SABOGAL|SAN MARCOS|DEPARTAMENTO CAJAMARCA	15,303	594.31	-7.000000	-78.000000	2
662	061101	061101 - SAN MIGUEL|SAN MIGUEL|DEPARTAMENTO CAJAMARCA	15,894	368.26	-7.000000	-79.000000	2
663	061102	061102 - BOLIVAR|SAN MIGUEL|DEPARTAMENTO CAJAMARCA	1,462	78.97	-7.000000	-79.000000	2
664	061103	061103 - CALQUIS|SAN MIGUEL|DEPARTAMENTO CAJAMARCA	4,425	339.00	-7.000000	-79.000000	2
665	061104	061104 - CATILLUC|SAN MIGUEL|DEPARTAMENTO CAJAMARCA	3,495	197.31	-7.000000	-79.000000	2
666	061105	061105 - EL PRADO|SAN MIGUEL|DEPARTAMENTO CAJAMARCA	1,300	71.44	-7.000000	-79.000000	2
667	061106	061106 - LA FLORIDA|SAN MIGUEL|DEPARTAMENTO CAJAMARCA	2,157	61.33	-7.000000	-79.000000	2
668	061107	061107 - LLAPA|SAN MIGUEL|DEPARTAMENTO CAJAMARCA	6,086	132.68	-7.000000	-79.000000	2
669	061108	061108 - NANCHOC|SAN MIGUEL|DEPARTAMENTO CAJAMARCA	1,550	358.94	-7.000000	-79.000000	2
670	061109	061109 - NIEPOS|SAN MIGUEL|DEPARTAMENTO CAJAMARCA	4,001	158.88	-7.000000	-79.000000	2
671	061110	061110 - SAN GREGORIO|SAN MIGUEL|DEPARTAMENTO CAJAMARCA	2,263	308.05	-7.000000	-79.000000	2
672	061111	061111 - SAN SILVESTRE DE COCHAN|SAN MIGUEL|DEPARTAMENTO CAJAMARCA	4,449	131.62	-7.000000	-79.000000	2
673	061112	061112 - TONGOD|SAN MIGUEL|DEPARTAMENTO CAJAMARCA	4,900	163.89	-7.000000	-79.000000	2
674	061113	061113 - UNION AGUA BLANCA|SAN MIGUEL|DEPARTAMENTO CAJAMARCA	3,577	171.71	-7.000000	-79.000000	2
675	061201	061201 - SAN PABLO|SAN PABLO|DEPARTAMENTO CAJAMARCA	13,586	197.92	-7.000000	-79.000000	2
676	061202	061202 - SAN BERNARDINO|SAN PABLO|DEPARTAMENTO CAJAMARCA	4,830	167.12	-7.000000	-79.000000	2
677	061203	061203 - SAN LUIS|SAN PABLO|DEPARTAMENTO CAJAMARCA	1,255	42.88	-7.000000	-79.000000	2
678	061204	061204 - TUMBADEN|SAN PABLO|DEPARTAMENTO CAJAMARCA	3,590	264.37	-7.000000	-79.000000	2
679	061301	061301 - SANTA CRUZ|SANTA CRUZ|DEPARTAMENTO CAJAMARCA	12,431	102.51	-7.000000	-79.000000	2
680	061302	061302 - ANDABAMBA|SANTA CRUZ|DEPARTAMENTO CAJAMARCA	1494	7.61	-7.000000	-79.000000	2
681	061303	061303 - CATACHE|SANTA CRUZ|DEPARTAMENTO CAJAMARCA	10,048	609.16	-7.000000	-79.000000	2
682	061304	061304 - CHANCAYBAÑOS|SANTA CRUZ|DEPARTAMENTO CAJAMARCA	3,899	120.04	-7.000000	-79.000000	2
683	061305	061305 - LA ESPERANZA|SANTA CRUZ|DEPARTAMENTO CAJAMARCA	2,560	59.70	-7.000000	-79.000000	2
684	061306	061306 - NINABAMBA|SANTA CRUZ|DEPARTAMENTO CAJAMARCA	2,759	60.04	-7.000000	-79.000000	2
685	061307	061307 - PULAN|SANTA CRUZ|DEPARTAMENTO CAJAMARCA	4,438	155.67	-7.000000	-79.000000	2
686	061308	061308 - SAUCEPAMPA|SANTA CRUZ|DEPARTAMENTO CAJAMARCA	1,848	31.58	-7.000000	-79.000000	2
687	061309	061309 - SEXI|SANTA CRUZ|DEPARTAMENTO CAJAMARCA	571	192.87	-7.000000	-79.000000	2
688	061310	061310 - UTICYACU|SANTA CRUZ|DEPARTAMENTO CAJAMARCA	1,606	43.38	-7.000000	-79.000000	2
689	061311	061311 - YAUYUCAN|SANTA CRUZ|DEPARTAMENTO CAJAMARCA	3,610	35.37	-7.000000	-79.000000	2
690	070101	070101 - CALLAO|CALLAO|DEPARTAMENTO CALLAO	410,795	45.65	-12.000000	-77.000000	2
691	070102	070102 - BELLAVISTA|CALLAO|DEPARTAMENTO CALLAO	72625	4.56	-12.000000	-77.000000	2
692	070103	070103 - CARMEN DE LA LEGUA|CALLAO|DEPARTAMENTO CALLAO	40,833	2.12	-12.000000	-77.000000	2
693	070104	070104 - LA PERLA|CALLAO|DEPARTAMENTO CALLAO	60,011	2.75	-12.000000	-77.000000	2
694	070105	070105 - LA PUNTA|CALLAO|DEPARTAMENTO CALLAO	3,184	18.38	-12.000000	-77.000000	2
695	070106	070106 - VENTANILLA|CALLAO|DEPARTAMENTO CALLAO	402,038	69.93	-12.000000	-77.000000	2
696	070107	070107 - MI PERU|CALLAO|DEPARTAMENTO CALLAO	63,542	2.52	-12.000000	-77.000000	2
697	080101	080101 - CUSCO|CUSCO|DEPARTAMENTO CUSCO	121,667	116.22	-14.000000	-72.000000	2
698	080102	080102 - CCORCA|CUSCO|DEPARTAMENTO CUSCO	2,483	188.56	-14.000000	-72.000000	2
699	080103	080103 - POROY|CUSCO|DEPARTAMENTO CUSCO	8,020	14.96	-13.000000	-72.000000	2
700	080104	080104 - SAN JERONIMO|CUSCO|DEPARTAMENTO CUSCO	48,224	103.34	-14.000000	-72.000000	2
701	080105	080105 - SAN SEBASTIAN|CUSCO|DEPARTAMENTO CUSCO	118,312	89.44	-14.000000	-72.000000	2
702	080106	080106 - SANTIAGO|CUSCO|DEPARTAMENTO CUSCO	91,838	69.72	-14.000000	-72.000000	2
703	080107	080107 - SAYLLA|CUSCO|DEPARTAMENTO CUSCO	5,599	28.38	-14.000000	-72.000000	2
704	080108	080108 - WANCHAQ|CUSCO|DEPARTAMENTO CUSCO	65,188	6.38	-14.000000	-72.000000	2
705	080201	080201 - ACOMAYO|ACOMAYO|DEPARTAMENTO CUSCO	5,627	141.27	-14.000000	-72.000000	2
706	080202	080202 - ACOPIA|ACOMAYO|DEPARTAMENTO CUSCO	2,336	91.72	-14.000000	-71.000000	2
707	080203	080203 - ACOS|ACOMAYO|DEPARTAMENTO CUSCO	2,286	137.55	-14.000000	-72.000000	2
708	080204	080204 - MOSOC LLACTA|ACOMAYO|DEPARTAMENTO CUSCO	2,321	43.61	-14.000000	-71.000000	2
709	080205	080205 - POMACANCHI|ACOMAYO|DEPARTAMENTO CUSCO	9,092	275.56	-14.000000	-72.000000	2
710	080206	080206 - RONDOCAN|ACOMAYO|DEPARTAMENTO CUSCO	2,281	180.22	-14.000000	-72.000000	2
711	080207	080207 - SANGARARA|ACOMAYO|DEPARTAMENTO CUSCO	3,733	78.29	-14.000000	-72.000000	2
712	080301	080301 - ANTA|ANTA|DEPARTAMENTO CUSCO	16,833	202.58	-13.000000	-72.000000	2
713	080302	080302 - ANCAHUASI|ANTA|DEPARTAMENTO CUSCO	7,015	123.58	-13.000000	-72.000000	2
714	080303	080303 - CACHIMAYO|ANTA|DEPARTAMENTO CUSCO	2,274	43.28	-13.000000	-72.000000	2
715	080304	080304 - CHINCHAYPUJIO|ANTA|DEPARTAMENTO CUSCO	4,434	390.58	-14.000000	-72.000000	2
716	080305	080305 - HUAROCONDO|ANTA|DEPARTAMENTO CUSCO	5,875	228.62	-13.000000	-72.000000	2
717	080306	080306 - LIMATAMBO|ANTA|DEPARTAMENTO CUSCO	9,813	512.92	-13.000000	-72.000000	2
718	080307	080307 - MOLLEPATA|ANTA|DEPARTAMENTO CUSCO	2,674	284.48	-14.000000	-73.000000	2
719	080308	080308 - PUCYURA|ANTA|DEPARTAMENTO CUSCO	4,242	37.75	-13.000000	-72.000000	2
720	080309	080309 - ZURITE|ANTA|DEPARTAMENTO CUSCO	3,714	52.33	-13.000000	-72.000000	2
721	080401	080401 - CALCA|CALCA|DEPARTAMENTO CUSCO	23,824	311.01	-13.000000	-72.000000	2
722	080402	080402 - COYA|CALCA|DEPARTAMENTO CUSCO	4,034	71.43	-13.000000	-72.000000	2
723	080403	080403 - LAMAY|CALCA|DEPARTAMENTO CUSCO	5,821	94.22	-13.000000	-72.000000	2
724	080404	080404 - LARES|CALCA|DEPARTAMENTO CUSCO	7,227	527.26	-13.000000	-72.000000	2
725	080405	080405 - PISAC|CALCA|DEPARTAMENTO CUSCO	10,285	148.25	-13.000000	-72.000000	2
726	080406	080406 - SAN SALVADOR|CALCA|DEPARTAMENTO CUSCO	5,674	128.07	-13.000000	-72.000000	2
727	080407	080407 - TARAY|CALCA|DEPARTAMENTO CUSCO	4,745	53.78	-13.000000	-72.000000	2
728	080408	080408 - YANATILE|CALCA|DEPARTAMENTO CUSCO	13,588	3,080.47	-13.000000	-72.000000	2
729	080501	080501 - YANAOCA|CANAS|DEPARTAMENTO CUSCO	10,178	292.97	-14.000000	-71.000000	2
730	080502	080502 - CHECCA|CANAS|DEPARTAMENTO CUSCO	6,315	503.76	-14.000000	-71.000000	2
731	080503	080503 - KUNTURKANKI|CANAS|DEPARTAMENTO CUSCO	5,781	376.19	-15.000000	-71.000000	2
732	080504	080504 - LANGUI|CANAS|DEPARTAMENTO CUSCO	2,567	187.10	-14.000000	-71.000000	2
733	080505	080505 - LAYO|CANAS|DEPARTAMENTO CUSCO	6447	452.56	-14.000000	-71.000000	2
734	080506	080506 - PAMPAMARCA|CANAS|DEPARTAMENTO CUSCO	2075	29.91	-14.000000	-71.000000	2
735	080507	080507 - QUEHUE|CANAS|DEPARTAMENTO CUSCO	3,578	143.46	-14.000000	-71.000000	2
736	080508	080508 - TUPAC AMARU|CANAS|DEPARTAMENTO CUSCO	2,961	117.81	-14.000000	-71.000000	2
737	080601	080601 - SICUANI|CANCHIS|DEPARTAMENTO CUSCO	60,903	645.88	-14.000000	-71.000000	2
738	080602	080602 - CHECACUPE|CANCHIS|DEPARTAMENTO CUSCO	4,984	962.34	-14.000000	-71.000000	2
739	080603	080603 - COMBAPATA|CANCHIS|DEPARTAMENTO CUSCO	5,432	182.50	-14.000000	-71.000000	2
740	080604	080604 - MARANGANI|CANCHIS|DEPARTAMENTO CUSCO	11,287	432.65	-14.000000	-71.000000	2
741	080605	080605 - PITUMARCA|CANCHIS|DEPARTAMENTO CUSCO	7,616	1,117.54	-14.000000	-71.000000	2
742	080606	080606 - SAN PABLO|CANCHIS|DEPARTAMENTO CUSCO	4557	524.06	-14.000000	-71.000000	2
743	080607	080607 - SAN PEDRO|CANCHIS|DEPARTAMENTO CUSCO	2,773	54.91	-14.000000	-71.000000	2
744	080608	080608 - TINTA|CANCHIS|DEPARTAMENTO CUSCO	5,642	79.39	-14.000000	-71.000000	2
745	080701	080701 - SANTO TOMAS|CHUMBIVILCAS|DEPARTAMENTO CUSCO	26,992	1,924.08	-14.000000	-72.000000	2
746	080702	080702 - CAPACMARCA|CHUMBIVILCAS|DEPARTAMENTO CUSCO	4,620	271.81	-14.000000	-72.000000	2
747	080703	080703 - CHAMACA|CHUMBIVILCAS|DEPARTAMENTO CUSCO	8,971	674.19	-14.000000	-72.000000	2
748	080704	080704 - COLQUEMARCA|CHUMBIVILCAS|DEPARTAMENTO CUSCO	8,630	449.49	-14.000000	-72.000000	2
749	080705	080705 - LIVITACA|CHUMBIVILCAS|DEPARTAMENTO CUSCO	13,526	758.20	-14.000000	-72.000000	2
750	080706	080706 - LLUSCO|CHUMBIVILCAS|DEPARTAMENTO CUSCO	7,173	315.42	-14.000000	-72.000000	2
751	080707	080707 - QUIÑOTA|CHUMBIVILCAS|DEPARTAMENTO CUSCO	4,990	221.05	-14.000000	-72.000000	2
752	080708	080708 - VELILLE|CHUMBIVILCAS|DEPARTAMENTO CUSCO	8,580	756.84	-15.000000	-72.000000	2
753	080801	080801 - ESPINAR|ESPINAR|DEPARTAMENTO CUSCO	33,970	747.78	-15.000000	-71.000000	2
754	080802	080802 - CONDOROMA|ESPINAR|DEPARTAMENTO CUSCO	1,431	513.36	-15.000000	-71.000000	2
755	080803	080803 - COPORAQUE|ESPINAR|DEPARTAMENTO CUSCO	18,004	1,564.46	-15.000000	-72.000000	2
756	080804	080804 - OCORURO|ESPINAR|DEPARTAMENTO CUSCO	1,588	353.15	-15.000000	-71.000000	2
757	080805	080805 - PALLPATA|ESPINAR|DEPARTAMENTO CUSCO	5,593	815.56	-15.000000	-71.000000	2
758	080806	080806 - PICHIGUA|ESPINAR|DEPARTAMENTO CUSCO	3,629	288.76	-15.000000	-71.000000	2
759	080807	080807 - SUYCKUTAMBO|ESPINAR|DEPARTAMENTO CUSCO	2,781	652.13	-15.000000	-72.000000	2
760	080808	080808 - ALTO PICHIGUA|ESPINAR|DEPARTAMENTO CUSCO	3,171	375.89	-15.000000	-71.000000	2
761	080901	080901 - SANTA ANA|LA CONVENCION|DEPARTAMENTO CUSCO	35,206	359.40	-13.000000	-73.000000	2
762	080902	080902 - ECHARATE|LA CONVENCION|DEPARTAMENTO CUSCO	37,130	19,135.50	-13.000000	-73.000000	2
763	080903	080903 - HUAYOPATA|LA CONVENCION|DEPARTAMENTO CUSCO	4,539	524.02	-13.000000	-73.000000	2
764	080904	080904 - MARANURA|LA CONVENCION|DEPARTAMENTO CUSCO	5,949	150.30	-13.000000	-73.000000	2
765	080905	080905 - OCOBAMBA|LA CONVENCION|DEPARTAMENTO CUSCO	6852	840.93	-13.000000	-72.000000	2
766	080906	080906 - QUELLOUNO|LA CONVENCION|DEPARTAMENTO CUSCO	18,320	799.68	-13.000000	-73.000000	2
767	080907	080907 - KIMBIRI|LA CONVENCION|DEPARTAMENTO CUSCO	14,893	905.69	-13.000000	-74.000000	2
768	080908	080908 - SANTA TERESA|LA CONVENCION|DEPARTAMENTO CUSCO	6,418	1,340.38	-13.000000	-73.000000	2
769	080909	080909 - VILCABAMBA|LA CONVENCION|DEPARTAMENTO CUSCO	13,869	3,318.86	-13.000000	-73.000000	2
770	080910	080910 - PICHARI|LA CONVENCION|DEPARTAMENTO CUSCO	20,538	730.45	-13.000000	-74.000000	2
771	080911	080911 - INKAWASI|LA CONVENCION|DEPARTAMENTO CUSCO	5109	1,101.65	-13.000000	-73.000000	2
772	080912	080912 - VILLA VIRGEN|LA CONVENCION|DEPARTAMENTO CUSCO	2,414	625.96	-13.000000	-74.000000	2
773	080913	080913 - VILLA KINTIARINA|LA CONVENCION|DEPARTAMENTO CUSCO	2,151	229.00	-13.000000	-74.000000	2
774	080914	080914 - MEGANTONI|LA CONVENCION|DEPARTAMENTO CUSCO	8441	10,708.16	-12.000000	-73.000000	2
775	081001	081001 - PARURO|PARURO|DEPARTAMENTO CUSCO	3,400	153.42	-14.000000	-72.000000	2
776	081002	081002 - ACCHA|PARURO|DEPARTAMENTO CUSCO	3,839	244.75	-14.000000	-72.000000	2
777	081003	081003 - CCAPI|PARURO|DEPARTAMENTO CUSCO	3,749	334.85	-14.000000	-72.000000	2
778	081004	081004 - COLCHA|PARURO|DEPARTAMENTO CUSCO	1,189	139.98	-14.000000	-72.000000	2
779	081005	081005 - HUANOQUITE|PARURO|DEPARTAMENTO CUSCO	5,700	362.67	-14.000000	-72.000000	2
780	081006	081006 - OMACHA|PARURO|DEPARTAMENTO CUSCO	7,205	436.21	-14.000000	-72.000000	2
781	081007	081007 - PACCARITAMBO|PARURO|DEPARTAMENTO CUSCO	2,076	142.61	-14.000000	-72.000000	2
782	081008	081008 - PILLPINTO|PARURO|DEPARTAMENTO CUSCO	1,254	79.13	-14.000000	-72.000000	2
783	081009	081009 - YAURISQUE|PARURO|DEPARTAMENTO CUSCO	2,522	90.80	-14.000000	-72.000000	2
784	081101	081101 - PAUCARTAMBO|PAUCARTAMBO|DEPARTAMENTO CUSCO	13,491	1,079.23	-13.000000	-72.000000	2
785	081102	081102 - CAICAY|PAUCARTAMBO|DEPARTAMENTO CUSCO	2,768	110.72	-14.000000	-72.000000	2
786	081103	081103 - CHALLABAMBA|PAUCARTAMBO|DEPARTAMENTO CUSCO	11,389	746.56	-13.000000	-72.000000	2
787	081104	081104 - COLQUEPATA|PAUCARTAMBO|DEPARTAMENTO CUSCO	10,767	467.68	-13.000000	-72.000000	2
788	081105	081105 - HUANCARANI|PAUCARTAMBO|DEPARTAMENTO CUSCO	7,774	145.14	-14.000000	-72.000000	2
789	081106	081106 - KOSÑIPATA|PAUCARTAMBO|DEPARTAMENTO CUSCO	5,692	3,745.68	-13.000000	-71.000000	2
790	081201	081201 - URCOS|QUISPICANCHI|DEPARTAMENTO CUSCO	9,412	134.65	-14.000000	-72.000000	2
791	081202	081202 - ANDAHUAYLILLAS|QUISPICANCHI|DEPARTAMENTO CUSCO	5,558	84.60	-14.000000	-72.000000	2
792	081203	081203 - CAMANTI|QUISPICANCHI|DEPARTAMENTO CUSCO	2,094	3,174.93	-13.000000	-71.000000	2
793	081204	081204 - CCARHUAYO|QUISPICANCHI|DEPARTAMENTO CUSCO	3,173	313.89	-14.000000	-71.000000	2
794	081205	081205 - CCATCA|QUISPICANCHI|DEPARTAMENTO CUSCO	18,128	307.72	-14.000000	-72.000000	2
795	081206	081206 - CUSIPATA|QUISPICANCHI|DEPARTAMENTO CUSCO	4795	248.03	-14.000000	-72.000000	2
796	081207	081207 - HUARO|QUISPICANCHI|DEPARTAMENTO CUSCO	4,508	106.28	-14.000000	-72.000000	2
797	081208	081208 - LUCRE|QUISPICANCHI|DEPARTAMENTO CUSCO	4,051	118.78	-14.000000	-72.000000	2
798	081209	081209 - MARCAPATA|QUISPICANCHI|DEPARTAMENTO CUSCO	4,533	1,687.91	-14.000000	-71.000000	2
799	081210	081210 - OCONGATE|QUISPICANCHI|DEPARTAMENTO CUSCO	15,889	952.66	-14.000000	-71.000000	2
800	081211	081211 - OROPESA|QUISPICANCHI|DEPARTAMENTO CUSCO	7,428	74.44	-14.000000	-72.000000	2
801	081212	081212 - QUIQUIJANA|QUISPICANCHI|DEPARTAMENTO CUSCO	11100	360.90	-14.000000	-72.000000	2
802	081301	081301 - URUBAMBA|URUBAMBA|DEPARTAMENTO CUSCO	21,424	128.28	-13.000000	-72.000000	2
803	081302	081302 - CHINCHERO|URUBAMBA|DEPARTAMENTO CUSCO	9,896	94.57	-13.000000	-72.000000	2
804	081303	081303 - HUAYLLABAMBA|URUBAMBA|DEPARTAMENTO CUSCO	5,332	102.47	-13.000000	-72.000000	2
805	081304	081304 - MACHUPICCHU|URUBAMBA|DEPARTAMENTO CUSCO	8,471	271.44	-13.000000	-73.000000	2
806	081305	081305 - MARAS|URUBAMBA|DEPARTAMENTO CUSCO	5,900	131.85	-13.000000	-72.000000	2
807	081306	081306 - OLLANTAYTAMBO|URUBAMBA|DEPARTAMENTO CUSCO	11,347	640.25	-13.000000	-72.000000	2
808	081307	081307 - YUCAY|URUBAMBA|DEPARTAMENTO CUSCO	3,390	70.57	-13.000000	-72.000000	2
809	090101	090101 - HUANCAVELICA|HUANCAVELICA|DEPARTAMENTO HUANCAVELICA	41,284	514.10	-13.000000	-75.000000	2
810	090102	090102 - ACOBAMBILLA|HUANCAVELICA|DEPARTAMENTO HUANCAVELICA	4,730	758.32	-13.000000	-75.000000	2
811	090103	090103 - ACORIA|HUANCAVELICA|DEPARTAMENTO HUANCAVELICA	37,509	535.10	-13.000000	-75.000000	2
812	090104	090104 - CONAYCA|HUANCAVELICA|DEPARTAMENTO HUANCAVELICA	1,212	37.79	-13.000000	-75.000000	2
813	090105	090105 - CUENCA|HUANCAVELICA|DEPARTAMENTO HUANCAVELICA	1,942	50.25	-12.000000	-75.000000	2
814	090106	090106 - HUACHOCOLPA|HUANCAVELICA|DEPARTAMENTO HUANCAVELICA	2,886	336.28	-13.000000	-75.000000	2
815	090107	090107 - HUAYLLAHUARA|HUANCAVELICA|DEPARTAMENTO HUANCAVELICA	724	38.80	-12.000000	-75.000000	2
816	090108	090108 - IZCUCHACA|HUANCAVELICA|DEPARTAMENTO HUANCAVELICA	868	12.19	-13.000000	-75.000000	2
817	090109	090109 - LARIA|HUANCAVELICA|DEPARTAMENTO HUANCAVELICA	1,453	78.45	-13.000000	-75.000000	2
818	090110	090110 - MANTA|HUANCAVELICA|DEPARTAMENTO HUANCAVELICA	1,876	154.14	-13.000000	-75.000000	2
819	090111	090111 - MARISCAL CACERES|HUANCAVELICA|DEPARTAMENTO HUANCAVELICA	1,058	5.63	-13.000000	-75.000000	2
820	090112	090112 - MOYA|HUANCAVELICA|DEPARTAMENTO HUANCAVELICA	2,539	94.08	-12.000000	-75.000000	2
821	090113	090113 - NUEVO OCCORO|HUANCAVELICA|DEPARTAMENTO HUANCAVELICA	2,728	211.56	-13.000000	-75.000000	2
822	090114	090114 - PALCA|HUANCAVELICA|DEPARTAMENTO HUANCAVELICA	3,214	82.08	-13.000000	-75.000000	2
823	090115	090115 - PILCHACA|HUANCAVELICA|DEPARTAMENTO HUANCAVELICA	487	42.97	-12.000000	-75.000000	2
824	090116	090116 - VILCA|HUANCAVELICA|DEPARTAMENTO HUANCAVELICA	3,060	317.76	-12.000000	-75.000000	2
825	090117	090117 - YAULI|HUANCAVELICA|DEPARTAMENTO HUANCAVELICA	34,557	319.92	-13.000000	-75.000000	2
826	090118	090118 - ASCENSION|HUANCAVELICA|DEPARTAMENTO HUANCAVELICA	12,711	432.24	-13.000000	-75.000000	2
827	090119	090119 - HUANDO|HUANCAVELICA|DEPARTAMENTO HUANCAVELICA	7,695	193.90	-13.000000	-75.000000	2
828	090201	090201 - ACOBAMBA|ACOBAMBA|DEPARTAMENTO HUANCAVELICA	10,258	123.02	-13.000000	-75.000000	2
829	090202	090202 - ANDABAMBA|ACOBAMBA|DEPARTAMENTO HUANCAVELICA	5758	81.85	-13.000000	-75.000000	2
830	090203	090203 - ANTA|ACOBAMBA|DEPARTAMENTO HUANCAVELICA	9,711	91.36	-13.000000	-75.000000	2
831	090204	090204 - CAJA|ACOBAMBA|DEPARTAMENTO HUANCAVELICA	2,837	82.39	-13.000000	-74.000000	2
832	090205	090205 - MARCAS|ACOBAMBA|DEPARTAMENTO HUANCAVELICA	2,402	155.87	-13.000000	-74.000000	2
833	090206	090206 - PAUCARA|ACOBAMBA|DEPARTAMENTO HUANCAVELICA	38,507	225.60	-13.000000	-75.000000	2
834	090207	090207 - POMACOCHA|ACOBAMBA|DEPARTAMENTO HUANCAVELICA	3,941	53.66	-13.000000	-75.000000	2
835	090208	090208 - ROSARIO|ACOBAMBA|DEPARTAMENTO HUANCAVELICA	7,985	97.07	-13.000000	-75.000000	2
836	090301	090301 - LIRCAY|ANGARAES|DEPARTAMENTO HUANCAVELICA	25,333	818.84	-13.000000	-75.000000	2
837	090302	090302 - ANCHONGA|ANGARAES|DEPARTAMENTO HUANCAVELICA	8,216	72.40	-13.000000	-75.000000	2
838	090303	090303 - CALLANMARCA|ANGARAES|DEPARTAMENTO HUANCAVELICA	757	26.02	-13.000000	-75.000000	2
839	090304	090304 - CCOCHACCASA|ANGARAES|DEPARTAMENTO HUANCAVELICA	2,665	116.60	-13.000000	-75.000000	2
840	090305	090305 - CHINCHO|ANGARAES|DEPARTAMENTO HUANCAVELICA	3,578	182.70	-13.000000	-74.000000	2
841	090306	090306 - CONGALLA|ANGARAES|DEPARTAMENTO HUANCAVELICA	4,165	215.64	-13.000000	-74.000000	2
842	090307	090307 - HUANCA-HUANCA|ANGARAES|DEPARTAMENTO HUANCAVELICA	1,773	109.96	-13.000000	-75.000000	2
843	090308	090308 - HUAYLLAY GRANDE|ANGARAES|DEPARTAMENTO HUANCAVELICA	2,240	33.28	-13.000000	-75.000000	2
844	090309	090309 - JULCAMARCA|ANGARAES|DEPARTAMENTO HUANCAVELICA	1,801	48.61	-13.000000	-74.000000	2
845	090310	090310 - SAN ANTONIO DE ANTAPARCO|ANGARAES|DEPARTAMENTO HUANCAVELICA	7,834	33.42	-13.000000	-74.000000	2
846	090311	090311 - SANTO TOMAS DE PATA|ANGARAES|DEPARTAMENTO HUANCAVELICA	2,735	133.57	-13.000000	-74.000000	2
847	090312	090312 - SECCLLA|ANGARAES|DEPARTAMENTO HUANCAVELICA	3,885	167.99	-13.000000	-74.000000	2
848	090401	090401 - CASTROVIRREYNA|CASTROVIRREYNA|DEPARTAMENTO HUANCAVELICA	3,265	937.94	-13.000000	-75.000000	2
849	090402	090402 - ARMA|CASTROVIRREYNA|DEPARTAMENTO HUANCAVELICA	1,439	304.85	-13.000000	-76.000000	2
850	090403	090403 - AURAHUA|CASTROVIRREYNA|DEPARTAMENTO HUANCAVELICA	2,275	360.97	-13.000000	-76.000000	2
851	090404	090404 - CAPILLAS|CASTROVIRREYNA|DEPARTAMENTO HUANCAVELICA	1,452	397.95	-13.000000	-76.000000	2
852	090405	090405 - CHUPAMARCA|CASTROVIRREYNA|DEPARTAMENTO HUANCAVELICA	1,233	373.78	-13.000000	-76.000000	2
853	090406	090406 - COCAS|CASTROVIRREYNA|DEPARTAMENTO HUANCAVELICA	916	87.95	-13.000000	-75.000000	2
854	090407	090407 - HUACHOS|CASTROVIRREYNA|DEPARTAMENTO HUANCAVELICA	1,676	172.01	-13.000000	-76.000000	2
855	090408	090408 - HUAMATAMBO|CASTROVIRREYNA|DEPARTAMENTO HUANCAVELICA	379	54.16	-13.000000	-76.000000	2
856	090409	090409 - MOLLEPAMPA|CASTROVIRREYNA|DEPARTAMENTO HUANCAVELICA	1,695	165.65	-13.000000	-75.000000	2
857	090410	090410 - SAN JUAN|CASTROVIRREYNA|DEPARTAMENTO HUANCAVELICA	440	207.25	-13.000000	-76.000000	2
858	090411	090411 - SANTA ANA|CASTROVIRREYNA|DEPARTAMENTO HUANCAVELICA	2,195	622.10	-13.000000	-75.000000	2
859	090412	090412 - TANTARA|CASTROVIRREYNA|DEPARTAMENTO HUANCAVELICA	717	113.01	-13.000000	-76.000000	2
860	090413	090413 - TICRAPO|CASTROVIRREYNA|DEPARTAMENTO HUANCAVELICA	1,598	187.00	-13.000000	-75.000000	2
861	090501	090501 - CHURCAMPA|CHURCAMPA|DEPARTAMENTO HUANCAVELICA	5,961	135.48	-13.000000	-74.000000	2
862	090502	090502 - ANCO|CHURCAMPA|DEPARTAMENTO HUANCAVELICA	6,609	150.18	-13.000000	-75.000000	2
863	090503	090503 - CHINCHIHUASI|CHURCAMPA|DEPARTAMENTO HUANCAVELICA	3,410	162.21	-13.000000	-75.000000	2
864	090504	090504 - EL CARMEN|CHURCAMPA|DEPARTAMENTO HUANCAVELICA	3,050	77.07	-13.000000	-74.000000	2
865	090505	090505 - LA MERCED|CHURCAMPA|DEPARTAMENTO HUANCAVELICA	1639	73.32	-13.000000	-74.000000	2
866	090506	090506 - LOCROJA|CHURCAMPA|DEPARTAMENTO HUANCAVELICA	4,179	92.48	-13.000000	-74.000000	2
867	090507	090507 - PAUCARBAMBA|CHURCAMPA|DEPARTAMENTO HUANCAVELICA	7,336	97.72	-13.000000	-75.000000	2
868	090508	090508 - SAN MIGUEL DE MAYOCC|CHURCAMPA|DEPARTAMENTO HUANCAVELICA	1,239	38.43	-13.000000	-74.000000	2
869	090509	090509 - SAN PEDRO DE CORIS|CHURCAMPA|DEPARTAMENTO HUANCAVELICA	4,451	128.90	-13.000000	-74.000000	2
870	090510	090510 - PACHAMARCA|CHURCAMPA|DEPARTAMENTO HUANCAVELICA	2,917	156.29	-13.000000	-75.000000	2
871	090511	090511 - COSME|CHURCAMPA|DEPARTAMENTO HUANCAVELICA	4,141	106.34	-13.000000	-75.000000	2
872	090601	090601 - HUAYTARA|HUAYTARA|DEPARTAMENTO HUANCAVELICA	2,108	401.25	-14.000000	-75.000000	2
873	090602	090602 - AYAVI|HUAYTARA|DEPARTAMENTO HUANCAVELICA	597	201.26	-14.000000	-75.000000	2
874	090603	090603 - CORDOVA|HUAYTARA|DEPARTAMENTO HUANCAVELICA	2,898	104.59	-14.000000	-75.000000	2
875	090604	090604 - HUAYACUNDO ARMA|HUAYTARA|DEPARTAMENTO HUANCAVELICA	469	12.81	-14.000000	-75.000000	2
876	090605	090605 - LARAMARCA|HUAYTARA|DEPARTAMENTO HUANCAVELICA	823	205.05	-14.000000	-75.000000	2
877	090606	090606 - OCOYO|HUAYTARA|DEPARTAMENTO HUANCAVELICA	2,488	154.71	-14.000000	-75.000000	2
878	090607	090607 - PILPICHACA|HUAYTARA|DEPARTAMENTO HUANCAVELICA	3,765	2,162.92	-13.000000	-75.000000	2
879	090608	090608 - QUERCO|HUAYTARA|DEPARTAMENTO HUANCAVELICA	1044	697.31	-14.000000	-75.000000	2
880	090609	090609 - QUITO-ARMA|HUAYTARA|DEPARTAMENTO HUANCAVELICA	767	222.32	-14.000000	-75.000000	2
881	090610	090610 - SAN ANTONIO DE CUSICANCHA|HUAYTARA|DEPARTAMENTO HUANCAVELICA	1,676	255.86	-14.000000	-75.000000	2
882	090611	090611 - SAN FRANCISCO DE SANGAYAICO|HUAYTARA|DEPARTAMENTO HUANCAVELICA	557	70.70	-14.000000	-75.000000	2
883	090612	090612 - SAN ISIDRO|HUAYTARA|DEPARTAMENTO HUANCAVELICA	1,194	174.95	-14.000000	-75.000000	2
884	090613	090613 - SANTIAGO DE CHOCORVOS|HUAYTARA|DEPARTAMENTO HUANCAVELICA	2,842	1,150.20	-14.000000	-75.000000	2
885	090614	090614 - SANTIAGO DE QUIRAHUARA|HUAYTARA|DEPARTAMENTO HUANCAVELICA	658	169.32	-14.000000	-75.000000	2
886	090615	090615 - SANTO DOMINGO DE CAPILLAS|HUAYTARA|DEPARTAMENTO HUANCAVELICA	982	248.56	-14.000000	-75.000000	2
887	090616	090616 - TAMBO|HUAYTARA|DEPARTAMENTO HUANCAVELICA	313	226.58	-14.000000	-75.000000	2
888	090701	090701 - PAMPAS|TAYACAJA|DEPARTAMENTO HUANCAVELICA	9,335	109.07	-12.000000	-75.000000	2
889	090702	090702 - ACOSTAMBO|TAYACAJA|DEPARTAMENTO HUANCAVELICA	4,110	168.06	-12.000000	-75.000000	2
890	090703	090703 - ACRAQUIA|TAYACAJA|DEPARTAMENTO HUANCAVELICA	5,014	110.27	-12.000000	-75.000000	2
891	090704	090704 - AHUAYCHA|TAYACAJA|DEPARTAMENTO HUANCAVELICA	5,619	90.96	-12.000000	-75.000000	2
892	090705	090705 - COLCABAMBA|TAYACAJA|DEPARTAMENTO HUANCAVELICA	12,376	312.18	-12.000000	-75.000000	2
893	090706	090706 - DANIEL HERNANDEZ|TAYACAJA|DEPARTAMENTO HUANCAVELICA	10,381	106.92	-12.000000	-75.000000	2
894	090707	090707 - HUACHOCOLPA|TAYACAJA|DEPARTAMENTO HUANCAVELICA	6,534	292.00	-12.000000	-75.000000	2
895	090709	090709 - HUARIBAMBA|TAYACAJA|DEPARTAMENTO HUANCAVELICA	4,472	150.69	-12.000000	-75.000000	2
896	090710	090710 - ÑAHUIMPUQUIO|TAYACAJA|DEPARTAMENTO HUANCAVELICA	1,876	67.39	-12.000000	-75.000000	2
897	090711	090711 - PAZOS|TAYACAJA|DEPARTAMENTO HUANCAVELICA	7,281	227.86	-12.000000	-75.000000	2
898	090713	090713 - QUISHUAR|TAYACAJA|DEPARTAMENTO HUANCAVELICA	904	31.54	-12.000000	-75.000000	2
899	090714	090714 - SALCABAMBA|TAYACAJA|DEPARTAMENTO HUANCAVELICA	4,584	192.52	-12.000000	-75.000000	2
900	090715	090715 - SALCAHUASI|TAYACAJA|DEPARTAMENTO HUANCAVELICA	3,294	117.98	-12.000000	-75.000000	2
901	090716	090716 - SAN MARCOS DE ROCCHAC|TAYACAJA|DEPARTAMENTO HUANCAVELICA	2,856	281.71	-12.000000	-75.000000	2
902	090717	090717 - SURCUBAMBA|TAYACAJA|DEPARTAMENTO HUANCAVELICA	4,926	271.75	-12.000000	-75.000000	2
903	090718	090718 - TINTAY PUNCU|TAYACAJA|DEPARTAMENTO HUANCAVELICA	9,140	257.73	-12.000000	-75.000000	2
904	090719	090719 - QUICHUAS|TAYACAJA|DEPARTAMENTO HUANCAVELICA	4145	114.16	-12.000000	-75.000000	2
905	090720	090720 - ANDAYMARCA|TAYACAJA|DEPARTAMENTO HUANCAVELICA	2,283	144.94	-12.000000	-75.000000	2
906	090721	090721 - ROBLE|TAYACAJA|DEPARTAMENTO HUANCAVELICA	4,440	186.03	-12.000000	-74.000000	2
907	090722	090722 - PICHOS|TAYACAJA|DEPARTAMENTO HUANCAVELICA	3501	144.60	-12.000000	-75.000000	2
908	090723	090723 - SANTIAGO DE TÚCUMA|TAYACAJA|DEPARTAMENTO HUANCAVELICA	2,117	34.02	-12.000000	-75.000000	2
909	100101	100101 - HUANUCO|HUANUCO|DEPARTAMENTO HUANUCO	88,542	126.23	-10.000000	-76.000000	2
910	100102	100102 - AMARILIS|HUANUCO|DEPARTAMENTO HUANUCO	79545	131.68	-10.000000	-76.000000	2
911	100103	100103 - CHINCHAO|HUANUCO|DEPARTAMENTO HUANUCO	14099	795.78	-10.000000	-76.000000	2
912	100104	100104 - CHURUBAMBA|HUANUCO|DEPARTAMENTO HUANUCO	28,908	507.31	-10.000000	-76.000000	2
913	100105	100105 - MARGOS|HUANUCO|DEPARTAMENTO HUANUCO	10,021	206.57	-10.000000	-77.000000	2
914	100106	100106 - QUISQUI|HUANUCO|DEPARTAMENTO HUANUCO	8,392	172.74	-10.000000	-76.000000	2
915	100107	100107 - SAN FRANCISCO DE CAYRAN|HUANUCO|DEPARTAMENTO HUANUCO	5,575	146.24	-10.000000	-76.000000	2
916	100108	100108 - SAN PEDRO DE CHAULAN|HUANUCO|DEPARTAMENTO HUANUCO	8,121	266.36	-10.000000	-76.000000	2
917	100109	100109 - SANTA MARIA DEL VALLE|HUANUCO|DEPARTAMENTO HUANUCO	20,984	446.63	-10.000000	-76.000000	2
918	100110	100110 - YARUMAYO|HUANUCO|DEPARTAMENTO HUANUCO	3,139	60.94	-10.000000	-76.000000	2
919	100111	100111 - PILLCO MARCA|HUANUCO|DEPARTAMENTO HUANUCO	28110	76.61	-10.000000	-76.000000	2
920	100112	100112 - YACUS|HUANUCO|DEPARTAMENTO HUANUCO	7390	69.90	-10.000000	-77.000000	2
921	100113	100113 - SAN PABLO DE PILLAO|HUANUCO|DEPARTAMENTO HUANUCO	12,223	584.60	-10.000000	-76.000000	2
922	100201	100201 - AMBO|AMBO|DEPARTAMENTO HUANUCO	17,328	288.80	-10.000000	-76.000000	2
923	100202	100202 - CAYNA|AMBO|DEPARTAMENTO HUANUCO	3,377	166.05	-10.000000	-76.000000	2
924	100203	100203 - COLPAS|AMBO|DEPARTAMENTO HUANUCO	2,388	174.34	-10.000000	-76.000000	2
925	100204	100204 - CONCHAMARCA|AMBO|DEPARTAMENTO HUANUCO	6,938	104.81	-10.000000	-76.000000	2
926	100205	100205 - HUACAR|AMBO|DEPARTAMENTO HUANUCO	7,642	234.23	-10.000000	-76.000000	2
927	100206	100206 - SAN FRANCISCO|AMBO|DEPARTAMENTO HUANUCO	3,233	121.21	-10.000000	-76.000000	2
928	100207	100207 - SAN RAFAEL|AMBO|DEPARTAMENTO HUANUCO	12,278	443.63	-10.000000	-76.000000	2
929	100208	100208 - TOMAY KICHWA|AMBO|DEPARTAMENTO HUANUCO	3,857	42.11	-10.000000	-76.000000	2
930	100301	100301 - LA UNION|DOS DE MAYO|DEPARTAMENTO HUANUCO	6,302	167.10	-10.000000	-77.000000	2
931	100307	100307 - CHUQUIS|DOS DE MAYO|DEPARTAMENTO HUANUCO	6,030	151.25	-10.000000	-77.000000	2
932	100311	100311 - MARIAS|DOS DE MAYO|DEPARTAMENTO HUANUCO	9,957	637.24	-10.000000	-77.000000	2
933	100313	100313 - PACHAS|DOS DE MAYO|DEPARTAMENTO HUANUCO	13,450	264.74	-10.000000	-77.000000	2
934	100316	100316 - QUIVILLA|DOS DE MAYO|DEPARTAMENTO HUANUCO	3,296	33.60	-10.000000	-77.000000	2
935	100317	100317 - RIPAN|DOS DE MAYO|DEPARTAMENTO HUANUCO	7,150	75.04	-10.000000	-77.000000	2
936	100321	100321 - SHUNQUI|DOS DE MAYO|DEPARTAMENTO HUANUCO	2,478	32.26	-10.000000	-77.000000	2
937	100322	100322 - SILLAPATA|DOS DE MAYO|DEPARTAMENTO HUANUCO	2,430	70.53	-10.000000	-77.000000	2
938	100323	100323 - YANAS|DOS DE MAYO|DEPARTAMENTO HUANUCO	3,339	36.31	-10.000000	-77.000000	2
939	100401	100401 - HUACAYBAMBA|HUACAYBAMBA|DEPARTAMENTO HUANUCO	7,290	586.21	-9.000000	-77.000000	2
940	100402	100402 - CANCHABAMBA|HUACAYBAMBA|DEPARTAMENTO HUANUCO	3,324	186.83	-9.000000	-77.000000	2
941	100403	100403 - COCHABAMBA|HUACAYBAMBA|DEPARTAMENTO HUANUCO	3,650	684.87	-9.000000	-77.000000	2
942	100404	100404 - PINRA|HUACAYBAMBA|DEPARTAMENTO HUANUCO	8,974	283.71	-9.000000	-77.000000	2
943	100501	100501 - LLATA|HUAMALIES|DEPARTAMENTO HUANUCO	15299	411.35	-10.000000	-77.000000	2
944	100502	100502 - ARANCAY|HUAMALIES|DEPARTAMENTO HUANUCO	1,603	158.33	-9.000000	-77.000000	2
945	100503	100503 - CHAVIN DE PARIARCA|HUAMALIES|DEPARTAMENTO HUANUCO	3,721	89.25	-9.000000	-77.000000	2
946	100504	100504 - JACAS GRANDE|HUAMALIES|DEPARTAMENTO HUANUCO	5,998	236.99	-10.000000	-77.000000	2
947	100505	100505 - JIRCAN|HUAMALIES|DEPARTAMENTO HUANUCO	3,739	84.81	-9.000000	-77.000000	2
948	100506	100506 - MIRAFLORES|HUAMALIES|DEPARTAMENTO HUANUCO	3,592	96.74	-9.000000	-77.000000	2
949	100507	100507 - MONZON|HUAMALIES|DEPARTAMENTO HUANUCO	29863	1,521.39	-9.000000	-76.000000	2
950	100508	100508 - PUNCHAO|HUAMALIES|DEPARTAMENTO HUANUCO	2,599	42.24	-9.000000	-77.000000	2
951	100509	100509 - PUÑOS|HUAMALIES|DEPARTAMENTO HUANUCO	4,316	101.75	-10.000000	-77.000000	2
952	100510	100510 - SINGA|HUAMALIES|DEPARTAMENTO HUANUCO	3,342	151.70	-9.000000	-77.000000	2
953	100511	100511 - TANTAMAYO|HUAMALIES|DEPARTAMENTO HUANUCO	3,035	249.95	-9.000000	-77.000000	2
954	100601	100601 - RUPA-RUPA|LEONCIO PRADO|DEPARTAMENTO HUANUCO	51,713	266.52	-9.000000	-76.000000	2
955	100602	100602 - DANIEL ALOMIAS ROBLES|LEONCIO PRADO|DEPARTAMENTO HUANUCO	8,011	702.46	-9.000000	-76.000000	2
956	100603	100603 - HERMILIO VALDIZAN|LEONCIO PRADO|DEPARTAMENTO HUANUCO	4,065	112.20	-9.000000	-76.000000	2
957	100604	100604 - JOSE CRESPO Y CASTILLO|LEONCIO PRADO|DEPARTAMENTO HUANUCO	25,403	2,120.66	-9.000000	-76.000000	2
958	100605	100605 - LUYANDO|LEONCIO PRADO|DEPARTAMENTO HUANUCO	10,078	100.32	-9.000000	-76.000000	2
959	100606	100606 - MARIANO DAMASO BERAUN|LEONCIO PRADO|DEPARTAMENTO HUANUCO	9,433	766.27	-9.000000	-76.000000	2
960	100607	100607 - PUCAYACU|LEONCIO PRADO|DEPARTAMENTO HUANUCO	4,790	768.35	-9.000000	-76.000000	2
961	100608	100608 - CASTILLO GRANDE|LEONCIO PRADO|DEPARTAMENTO HUANUCO	13,409	106.11	-9.000000	-76.000000	2
962	100609	100609 - PUEBLO NUEVO|LEONCIO PRADO|DEPARTAMENTO HUANUCO	5,745	320.38	-9.000000	-76.000000	2
963	100610	100610 - SANTO DOMINGO DE ANDA|LEONCIO PRADO|DEPARTAMENTO HUANUCO	3,767	283.54	-9.000000	-76.000000	2
964	100701	100701 - HUACRACHUCO|MARAÑON|DEPARTAMENTO HUANUCO	15,942	704.63	-9.000000	-77.000000	2
965	100702	100702 - CHOLON|MARAÑON|DEPARTAMENTO HUANUCO	10,428	2,125.19	-9.000000	-77.000000	2
966	100703	100703 - SAN BUENAVENTURA|MARAÑON|DEPARTAMENTO HUANUCO	2,744	86.54	-9.000000	-77.000000	2
967	100704	100704 - LA MORADA|MARAÑON|DEPARTAMENTO HUANUCO	1,863	878.94	-9.000000	-76.000000	2
968	100705	100705 - SANTA ROSA DE ALTO YANAJANCA|MARAÑON|DEPARTAMENTO HUANUCO	2,337	1,005.96	-9.000000	-76.000000	2
969	100801	100801 - PANAO|PACHITEA|DEPARTAMENTO HUANUCO	25,270	1,580.86	-10.000000	-76.000000	2
970	100802	100802 - CHAGLLA|PACHITEA|DEPARTAMENTO HUANUCO	14,914	1,103.58	-10.000000	-76.000000	2
971	100803	100803 - MOLINO|PACHITEA|DEPARTAMENTO HUANUCO	15,486	235.50	-10.000000	-76.000000	2
972	100804	100804 - UMARI|PACHITEA|DEPARTAMENTO HUANUCO	22,098	149.08	-10.000000	-76.000000	2
973	100901	100901 - PUERTO INCA|PUERTO INCA|DEPARTAMENTO HUANUCO	7,538	2,147.18	-9.000000	-75.000000	2
974	100902	100902 - CODO DEL POZUZO|PUERTO INCA|DEPARTAMENTO HUANUCO	6,630	3,322.04	-10.000000	-75.000000	2
975	100903	100903 - HONORIA|PUERTO INCA|DEPARTAMENTO HUANUCO	6,330	798.05	-9.000000	-75.000000	2
976	100904	100904 - TOURNAVISTA|PUERTO INCA|DEPARTAMENTO HUANUCO	4,559	2,228.46	-9.000000	-75.000000	2
977	100905	100905 - YUYAPICHIS|PUERTO INCA|DEPARTAMENTO HUANUCO	6,616	1,845.62	-10.000000	-75.000000	2
978	101001	101001 - JESUS|LAURICOCHA|DEPARTAMENTO HUANUCO	5,712	449.90	-10.000000	-77.000000	2
979	101002	101002 - BAÑOS|LAURICOCHA|DEPARTAMENTO HUANUCO	7,184	152.66	-10.000000	-77.000000	2
980	101003	101003 - JIVIA|LAURICOCHA|DEPARTAMENTO HUANUCO	2,925	61.31	-10.000000	-77.000000	2
981	101004	101004 - QUEROPALCA|LAURICOCHA|DEPARTAMENTO HUANUCO	3,090	131.15	-10.000000	-77.000000	2
982	101005	101005 - RONDOS|LAURICOCHA|DEPARTAMENTO HUANUCO	7,687	169.42	-10.000000	-77.000000	2
983	101006	101006 - SAN FRANCISCO DE ASIS|LAURICOCHA|DEPARTAMENTO HUANUCO	2,126	84.30	-10.000000	-77.000000	2
984	101007	101007 - SAN MIGUEL DE CAURI|LAURICOCHA|DEPARTAMENTO HUANUCO	10,381	811.75	-10.000000	-77.000000	2
985	101101	101101 - CHAVINILLO|YAROWILCA|DEPARTAMENTO HUANUCO	5,943	205.16	-10.000000	-77.000000	2
986	101102	101102 - CAHUAC|YAROWILCA|DEPARTAMENTO HUANUCO	4687	29.50	-10.000000	-77.000000	2
987	101103	101103 - CHACABAMBA|YAROWILCA|DEPARTAMENTO HUANUCO	3,760	16.53	-10.000000	-77.000000	2
988	101104	101104 - APARICIO POMARES|YAROWILCA|DEPARTAMENTO HUANUCO	5,488	183.14	-10.000000	-77.000000	2
989	101105	101105 - JACAS CHICO|YAROWILCA|DEPARTAMENTO HUANUCO	2,038	36.16	-10.000000	-77.000000	2
990	101106	101106 - OBAS|YAROWILCA|DEPARTAMENTO HUANUCO	5,435	123.16	-10.000000	-77.000000	2
991	101107	101107 - PAMPAMARCA|YAROWILCA|DEPARTAMENTO HUANUCO	2039	72.68	-10.000000	-77.000000	2
992	101108	101108 - CHORAS|YAROWILCA|DEPARTAMENTO HUANUCO	3,665	61.14	-10.000000	-77.000000	2
993	110101	110101 - ICA|ICA|DEPARTAMENTO ICA	134,249	887.51	-14.000000	-76.000000	2
994	110102	110102 - LA TINGUIÑA|ICA|DEPARTAMENTO ICA	36,909	98.34	-14.000000	-76.000000	2
995	110103	110103 - LOS AQUIJES|ICA|DEPARTAMENTO ICA	19,987	90.92	-14.000000	-76.000000	2
996	110104	110104 - OCUCAJE|ICA|DEPARTAMENTO ICA	3,816	1,417.12	-14.000000	-76.000000	2
997	110105	110105 - PACHACUTEC|ICA|DEPARTAMENTO ICA	6,949	34.47	-14.000000	-76.000000	2
998	110106	110106 - PARCONA|ICA|DEPARTAMENTO ICA	56,336	17.39	-14.000000	-76.000000	2
999	110107	110107 - PUEBLO NUEVO|ICA|DEPARTAMENTO ICA	4,843	33.12	-14.000000	-76.000000	2
1000	110108	110108 - SALAS|ICA|DEPARTAMENTO ICA	24557	651.72	-14.000000	-76.000000	2
1001	110109	110109 - SAN JOSE DE LOS MOLINOS|ICA|DEPARTAMENTO ICA	6360	363.20	-14.000000	-76.000000	2
1002	110110	110110 - SAN JUAN BAUTISTA|ICA|DEPARTAMENTO ICA	15,214	26.39	-14.000000	-76.000000	2
1003	110111	110111 - SANTIAGO|ICA|DEPARTAMENTO ICA	30,313	2,783.73	-14.000000	-76.000000	2
1004	110112	110112 - SUBTANJALLA|ICA|DEPARTAMENTO ICA	29063	193.97	-14.000000	-76.000000	2
1005	110113	110113 - TATE|ICA|DEPARTAMENTO ICA	4,719	7.07	-14.000000	-76.000000	2
1006	110114	110114 - YAUCA DEL ROSARIO|ICA|DEPARTAMENTO ICA	972	1,289.10	-14.000000	-75.000000	2
1007	110201	110201 - CHINCHA ALTA|CHINCHA|DEPARTAMENTO ICA	65,322	238.34	-13.000000	-76.000000	2
1008	110202	110202 - ALTO LARAN|CHINCHA|DEPARTAMENTO ICA	7,663	298.83	-13.000000	-76.000000	2
1009	110203	110203 - CHAVIN|CHINCHA|DEPARTAMENTO ICA	1,470	426.17	-13.000000	-76.000000	2
1010	110204	110204 - CHINCHA BAJA|CHINCHA|DEPARTAMENTO ICA	12,536	72.52	-13.000000	-76.000000	2
1011	110205	110205 - EL CARMEN|CHINCHA|DEPARTAMENTO ICA	13,734	789.90	-14.000000	-76.000000	2
1012	110206	110206 - GROCIO PRADO|CHINCHA|DEPARTAMENTO ICA	24,910	190.53	-13.000000	-76.000000	2
1013	110207	110207 - PUEBLO NUEVO|CHINCHA|DEPARTAMENTO ICA	63,297	209.45	-13.000000	-76.000000	2
1014	110208	110208 - SAN JUAN DE YANAC|CHINCHA|DEPARTAMENTO ICA	269	500.40	-13.000000	-76.000000	2
1015	110209	110209 - SAN PEDRO DE HUACARPANA|CHINCHA|DEPARTAMENTO ICA	1,700	222.45	-13.000000	-76.000000	2
1016	110210	110210 - SUNAMPE|CHINCHA|DEPARTAMENTO ICA	28435	16.76	-13.000000	-76.000000	2
1017	110211	110211 - TAMBO DE MORA|CHINCHA|DEPARTAMENTO ICA	5,110	22.00	-13.000000	-76.000000	2
1018	110301	110301 - NAZCA|NAZCA|DEPARTAMENTO ICA	27,249	1,252.25	-15.000000	-75.000000	2
1019	110302	110302 - CHANGUILLO|NAZCA|DEPARTAMENTO ICA	1,457	946.94	-15.000000	-75.000000	2
1020	110303	110303 - EL INGENIO|NAZCA|DEPARTAMENTO ICA	2,697	552.39	-15.000000	-75.000000	2
1021	110304	110304 - MARCONA|NAZCA|DEPARTAMENTO ICA	12,510	1,955.20	-15.000000	-75.000000	2
1022	110305	110305 - VISTA ALEGRE|NAZCA|DEPARTAMENTO ICA	15,935	527.30	-15.000000	-75.000000	2
1023	110401	110401 - PALPA|PALPA|DEPARTAMENTO ICA	7,279	147.44	-15.000000	-75.000000	2
1024	110402	110402 - LLIPATA|PALPA|DEPARTAMENTO ICA	1,516	186.18	-15.000000	-75.000000	2
1025	110403	110403 - RIO GRANDE|PALPA|DEPARTAMENTO ICA	2,236	315.52	-15.000000	-75.000000	2
1026	110404	110404 - SANTA CRUZ|PALPA|DEPARTAMENTO ICA	987	255.70	-14.000000	-75.000000	2
1027	110405	110405 - TIBILLO|PALPA|DEPARTAMENTO ICA	316	328.04	-14.000000	-75.000000	2
1028	110501	110501 - PISCO|PISCO|DEPARTAMENTO ICA	54716	24.56	-14.000000	-76.000000	2
1029	110502	110502 - HUANCANO|PISCO|DEPARTAMENTO ICA	1,577	905.14	-14.000000	-76.000000	2
1030	110503	110503 - HUMAY|PISCO|DEPARTAMENTO ICA	6,041	1,112.96	-14.000000	-76.000000	2
1031	110504	110504 - INDEPENDENCIA|PISCO|DEPARTAMENTO ICA	14,928	272.34	-14.000000	-76.000000	2
1032	110505	110505 - PARACAS|PISCO|DEPARTAMENTO ICA	7,390	1,420.00	-14.000000	-76.000000	2
1033	110506	110506 - SAN ANDRES|PISCO|DEPARTAMENTO ICA	13,733	39.45	-14.000000	-76.000000	2
1034	110507	110507 - SAN CLEMENTE|PISCO|DEPARTAMENTO ICA	22,548	127.22	-14.000000	-76.000000	2
1035	110508	110508 - TUPAC AMARU INCA|PISCO|DEPARTAMENTO ICA	18,366	55.48	-14.000000	-76.000000	2
1036	120101	120101 - HUANCAYO|HUANCAYO|DEPARTAMENTO JUNIN	119,025	237.55	-12.000000	-75.000000	2
1037	120104	120104 - CARHUACALLANGA|HUANCAYO|DEPARTAMENTO JUNIN	1,398	13.78	-12.000000	-75.000000	2
1038	120105	120105 - CHACAPAMPA|HUANCAYO|DEPARTAMENTO JUNIN	810	120.72	-12.000000	-75.000000	2
1039	120106	120106 - CHICCHE|HUANCAYO|DEPARTAMENTO JUNIN	901	45.92	-12.000000	-75.000000	2
1040	120107	120107 - CHILCA|HUANCAYO|DEPARTAMENTO JUNIN	87,993	8.30	-12.000000	-75.000000	2
1041	120108	120108 - CHONGOS ALTO|HUANCAYO|DEPARTAMENTO JUNIN	1,339	701.75	-12.000000	-75.000000	2
1042	120111	120111 - CHUPURO|HUANCAYO|DEPARTAMENTO JUNIN	1,752	13.56	-12.000000	-75.000000	2
1043	120112	120112 - COLCA|HUANCAYO|DEPARTAMENTO JUNIN	2,068	113.25	-12.000000	-75.000000	2
1044	120113	120113 - CULLHUAS|HUANCAYO|DEPARTAMENTO JUNIN	2,204	108.01	-12.000000	-75.000000	2
1045	120114	120114 - EL TAMBO|HUANCAYO|DEPARTAMENTO JUNIN	166,163	73.56	-12.000000	-75.000000	2
1046	120116	120116 - HUACRAPUQUIO|HUANCAYO|DEPARTAMENTO JUNIN	1,274	24.10	-12.000000	-75.000000	2
1047	120117	120117 - HUALHUAS|HUANCAYO|DEPARTAMENTO JUNIN	4,630	24.82	-12.000000	-75.000000	2
1048	120119	120119 - HUANCAN|HUANCAYO|DEPARTAMENTO JUNIN	21,744	12.00	-12.000000	-75.000000	2
1049	120120	120120 - HUASICANCHA|HUANCAYO|DEPARTAMENTO JUNIN	842	47.61	-12.000000	-75.000000	2
1050	120121	120121 - HUAYUCACHI|HUANCAYO|DEPARTAMENTO JUNIN	8,665	13.13	-12.000000	-75.000000	2
1051	120122	120122 - INGENIO|HUANCAYO|DEPARTAMENTO JUNIN	2,507	53.29	-12.000000	-75.000000	2
1052	120124	120124 - PARIAHUANCA|HUANCAYO|DEPARTAMENTO JUNIN	5,767	617.50	-12.000000	-75.000000	2
1053	120125	120125 - PILCOMAYO|HUANCAYO|DEPARTAMENTO JUNIN	17,062	20.18	-12.000000	-75.000000	2
1054	120126	120126 - PUCARA|HUANCAYO|DEPARTAMENTO JUNIN	5,008	110.49	-12.000000	-75.000000	2
1055	120127	120127 - QUICHUAY|HUANCAYO|DEPARTAMENTO JUNIN	1,745	34.79	-12.000000	-75.000000	2
1056	120128	120128 - QUILCAS|HUANCAYO|DEPARTAMENTO JUNIN	4,268	167.98	-12.000000	-75.000000	2
1057	120129	120129 - SAN AGUSTIN|HUANCAYO|DEPARTAMENTO JUNIN	11,955	23.09	-12.000000	-75.000000	2
1058	120130	120130 - SAN JERONIMO DE TUNAN|HUANCAYO|DEPARTAMENTO JUNIN	10,420	20.99	-12.000000	-75.000000	2
1059	120132	120132 - SAÑO|HUANCAYO|DEPARTAMENTO JUNIN	4082	11.59	-12.000000	-75.000000	2
1060	120133	120133 - SAPALLANGA|HUANCAYO|DEPARTAMENTO JUNIN	12,879	119.02	-12.000000	-75.000000	2
1061	120134	120134 - SICAYA|HUANCAYO|DEPARTAMENTO JUNIN	8,166	42.73	-12.000000	-75.000000	2
1062	120135	120135 - SANTO DOMINGO DE ACOBAMBA|HUANCAYO|DEPARTAMENTO JUNIN	7,776	778.02	-12.000000	-75.000000	2
1063	120136	120136 - VIQUES|HUANCAYO|DEPARTAMENTO JUNIN	2,247	3.57	-12.000000	-75.000000	2
1064	120201	120201 - CONCEPCION|CONCEPCION|DEPARTAMENTO JUNIN	15,010	18.29	-12.000000	-75.000000	2
1065	120202	120202 - ACO|CONCEPCION|DEPARTAMENTO JUNIN	1,592	37.80	-12.000000	-75.000000	2
1066	120203	120203 - ANDAMARCA|CONCEPCION|DEPARTAMENTO JUNIN	4,536	694.90	-12.000000	-75.000000	2
1067	120204	120204 - CHAMBARA|CONCEPCION|DEPARTAMENTO JUNIN	2,882	113.21	-12.000000	-75.000000	2
1068	120205	120205 - COCHAS|CONCEPCION|DEPARTAMENTO JUNIN	1,757	165.05	-12.000000	-75.000000	2
1069	120206	120206 - COMAS|CONCEPCION|DEPARTAMENTO JUNIN	6,073	825.29	-12.000000	-75.000000	2
1070	120207	120207 - HEROINAS TOLEDO|CONCEPCION|DEPARTAMENTO JUNIN	1,193	25.83	-12.000000	-75.000000	2
1071	120208	120208 - MANZANARES|CONCEPCION|DEPARTAMENTO JUNIN	1,391	20.58	-12.000000	-75.000000	2
1072	120209	120209 - MARISCAL CASTILLA|CONCEPCION|DEPARTAMENTO JUNIN	1,652	743.84	-12.000000	-75.000000	2
1073	120210	120210 - MATAHUASI|CONCEPCION|DEPARTAMENTO JUNIN	5,171	24.74	-12.000000	-75.000000	2
1074	120211	120211 - MITO|CONCEPCION|DEPARTAMENTO JUNIN	1,369	25.21	-12.000000	-75.000000	2
1075	120212	120212 - NUEVE DE JULIO|CONCEPCION|DEPARTAMENTO JUNIN	1,485	7.28	-12.000000	-75.000000	2
1076	120213	120213 - ORCOTUNA|CONCEPCION|DEPARTAMENTO JUNIN	4,168	44.61	-12.000000	-75.000000	2
1077	120214	120214 - SAN JOSE DE QUERO|CONCEPCION|DEPARTAMENTO JUNIN	6,106	314.80	-12.000000	-76.000000	2
1078	120215	120215 - SANTA ROSA DE OCOPA|CONCEPCION|DEPARTAMENTO JUNIN	2,030	13.91	-12.000000	-75.000000	2
1079	120301	120301 - CHANCHAMAYO|CHANCHAMAYO|DEPARTAMENTO JUNIN	24,866	919.72	-11.000000	-75.000000	2
1080	120302	120302 - PERENE|CHANCHAMAYO|DEPARTAMENTO JUNIN	77,635	1,191.16	-11.000000	-75.000000	2
1081	120303	120303 - PICHANAQUI|CHANCHAMAYO|DEPARTAMENTO JUNIN	71,598	1,496.59	-11.000000	-75.000000	2
1082	120304	120304 - SAN LUIS DE SHUARO|CHANCHAMAYO|DEPARTAMENTO JUNIN	7,395	212.49	-11.000000	-75.000000	2
1083	120305	120305 - SAN RAMON|CHANCHAMAYO|DEPARTAMENTO JUNIN	27,630	591.67	-11.000000	-75.000000	2
1084	120306	120306 - VITOC|CHANCHAMAYO|DEPARTAMENTO JUNIN	1734	313.85	-11.000000	-75.000000	2
1085	120401	120401 - JAUJA|JAUJA|DEPARTAMENTO JUNIN	14,536	10.10	-12.000000	-75.000000	2
1086	120402	120402 - ACOLLA|JAUJA|DEPARTAMENTO JUNIN	7215	122.40	-12.000000	-76.000000	2
1087	120403	120403 - APATA|JAUJA|DEPARTAMENTO JUNIN	4,084	421.62	-12.000000	-75.000000	2
1088	120404	120404 - ATAURA|JAUJA|DEPARTAMENTO JUNIN	1,155	5.90	-12.000000	-75.000000	2
1089	120405	120405 - CANCHAYLLO|JAUJA|DEPARTAMENTO JUNIN	1,658	974.69	-12.000000	-76.000000	2
1090	120406	120406 - CURICACA|JAUJA|DEPARTAMENTO JUNIN	1,645	64.68	-12.000000	-76.000000	2
1091	120407	120407 - EL MANTARO|JAUJA|DEPARTAMENTO JUNIN	2,562	17.76	-12.000000	-75.000000	2
1092	120408	120408 - HUAMALI|JAUJA|DEPARTAMENTO JUNIN	1,809	20.19	-12.000000	-75.000000	2
1093	120409	120409 - HUARIPAMPA|JAUJA|DEPARTAMENTO JUNIN	836	14.19	-12.000000	-75.000000	2
1094	120410	120410 - HUERTAS|JAUJA|DEPARTAMENTO JUNIN	1644	11.82	-12.000000	-75.000000	2
1095	120411	120411 - JANJAILLO|JAUJA|DEPARTAMENTO JUNIN	681	31.57	-12.000000	-76.000000	2
1096	120412	120412 - JULCAN|JAUJA|DEPARTAMENTO JUNIN	671	24.78	-12.000000	-75.000000	2
1097	120413	120413 - LEONOR ORDOÑEZ|JAUJA|DEPARTAMENTO JUNIN	1,480	20.34	-12.000000	-75.000000	2
1098	120414	120414 - LLOCLLAPAMPA|JAUJA|DEPARTAMENTO JUNIN	1001	110.60	-12.000000	-76.000000	2
1099	120415	120415 - MARCO|JAUJA|DEPARTAMENTO JUNIN	1,590	28.80	-12.000000	-76.000000	2
1100	120416	120416 - MASMA|JAUJA|DEPARTAMENTO JUNIN	2,065	14.26	-12.000000	-75.000000	2
1101	120417	120417 - MASMA CHICCHE|JAUJA|DEPARTAMENTO JUNIN	757	29.86	-12.000000	-75.000000	2
1102	120418	120418 - MOLINOS|JAUJA|DEPARTAMENTO JUNIN	1,522	312.17	-12.000000	-75.000000	2
1103	120419	120419 - MONOBAMBA|JAUJA|DEPARTAMENTO JUNIN	1,055	295.83	-11.000000	-75.000000	2
1104	120420	120420 - MUQUI|JAUJA|DEPARTAMENTO JUNIN	960	11.74	-12.000000	-75.000000	2
1105	120421	120421 - MUQUIYAUYO|JAUJA|DEPARTAMENTO JUNIN	2,210	19.86	-12.000000	-75.000000	2
1106	120422	120422 - PACA|JAUJA|DEPARTAMENTO JUNIN	988	34.22	-12.000000	-76.000000	2
1107	120423	120423 - PACCHA|JAUJA|DEPARTAMENTO JUNIN	1,805	90.86	-12.000000	-76.000000	2
1108	120424	120424 - PANCAN|JAUJA|DEPARTAMENTO JUNIN	1,280	10.89	-12.000000	-75.000000	2
1109	120425	120425 - PARCO|JAUJA|DEPARTAMENTO JUNIN	1152	32.82	-12.000000	-76.000000	2
1110	120426	120426 - POMACANCHA|JAUJA|DEPARTAMENTO JUNIN	1,975	281.61	-12.000000	-76.000000	2
1111	120427	120427 - RICRAN|JAUJA|DEPARTAMENTO JUNIN	1,567	319.95	-12.000000	-76.000000	2
1112	120428	120428 - SAN LORENZO|JAUJA|DEPARTAMENTO JUNIN	2,509	22.15	-12.000000	-75.000000	2
1113	120429	120429 - SAN PEDRO DE CHUNAN|JAUJA|DEPARTAMENTO JUNIN	839	8.44	-12.000000	-75.000000	2
1114	120430	120430 - SAUSA|JAUJA|DEPARTAMENTO JUNIN	3,081	4.50	-12.000000	-75.000000	2
1115	120431	120431 - SINCOS|JAUJA|DEPARTAMENTO JUNIN	4,912	236.74	-12.000000	-75.000000	2
1116	120432	120432 - TUNAN MARCA|JAUJA|DEPARTAMENTO JUNIN	1,165	30.07	-12.000000	-76.000000	2
1117	120433	120433 - YAULI|JAUJA|DEPARTAMENTO JUNIN	1,304	93.15	-12.000000	-75.000000	2
1118	120434	120434 - YAUYOS|JAUJA|DEPARTAMENTO JUNIN	9,360	20.54	-12.000000	-75.000000	2
1119	120501	120501 - JUNIN|JUNIN|DEPARTAMENTO JUNIN	9,492	883.80	-11.000000	-76.000000	2
1120	120502	120502 - CARHUAMAYO|JUNIN|DEPARTAMENTO JUNIN	7,768	219.68	-11.000000	-76.000000	2
1121	120503	120503 - ONDORES|JUNIN|DEPARTAMENTO JUNIN	1,828	254.46	-11.000000	-76.000000	2
1122	120504	120504 - ULCUMAYO|JUNIN|DEPARTAMENTO JUNIN	5,783	1,129.37	-11.000000	-76.000000	2
1123	120601	120601 - SATIPO|SATIPO|DEPARTAMENTO JUNIN	43,554	732.02	-11.000000	-75.000000	2
1124	120602	120602 - COVIRIALI|SATIPO|DEPARTAMENTO JUNIN	6,332	145.13	-11.000000	-75.000000	2
1125	120603	120603 - LLAYLLA|SATIPO|DEPARTAMENTO JUNIN	6,417	180.39	-11.000000	-75.000000	2
1126	120604	120604 - MAZAMARI|SATIPO|DEPARTAMENTO JUNIN	65,014	2,219.63	-11.000000	-75.000000	2
1127	120605	120605 - PAMPA HERMOSA|SATIPO|DEPARTAMENTO JUNIN	10,899	566.82	-11.000000	-75.000000	2
1128	120606	120606 - PANGOA|SATIPO|DEPARTAMENTO JUNIN	60,587	3,679.40	-11.000000	-75.000000	2
1129	120607	120607 - RIO NEGRO|SATIPO|DEPARTAMENTO JUNIN	29,250	714.98	-11.000000	-74.000000	2
1130	120608	120608 - RIO TAMBO|SATIPO|DEPARTAMENTO JUNIN	61,259	10,349.90	-11.000000	-75.000000	2
1131	120609	120609 - VIZCATÁN DEL ENE|SATIPO|DEPARTAMENTO JUNIN	3,573	631.21	-12.000000	-74.000000	2
1132	120701	120701 - TARMA|TARMA|DEPARTAMENTO JUNIN	46,130	459.95	-11.000000	-76.000000	2
1133	120702	120702 - ACOBAMBA|TARMA|DEPARTAMENTO JUNIN	13,586	97.84	-11.000000	-76.000000	2
1134	120703	120703 - HUARICOLCA|TARMA|DEPARTAMENTO JUNIN	3,251	162.31	-12.000000	-76.000000	2
1135	120704	120704 - HUASAHUASI|TARMA|DEPARTAMENTO JUNIN	15,398	652.15	-11.000000	-76.000000	2
1136	120705	120705 - LA UNION|TARMA|DEPARTAMENTO JUNIN	3124	140.40	-11.000000	-76.000000	2
1137	120706	120706 - PALCA|TARMA|DEPARTAMENTO JUNIN	5,585	378.08	-11.000000	-76.000000	2
1138	120707	120707 - PALCAMAYO|TARMA|DEPARTAMENTO JUNIN	9,573	169.24	-11.000000	-76.000000	2
1139	120708	120708 - SAN PEDRO DE CAJAS|TARMA|DEPARTAMENTO JUNIN	5,669	537.31	-11.000000	-76.000000	2
1140	120709	120709 - TAPO|TARMA|DEPARTAMENTO JUNIN	6,073	151.88	-11.000000	-76.000000	2
1141	120801	120801 - LA OROYA|YAULI|DEPARTAMENTO JUNIN	12,577	388.42	-12.000000	-76.000000	2
1142	120802	120802 - CHACAPALPA|YAULI|DEPARTAMENTO JUNIN	704	183.06	-12.000000	-76.000000	2
1143	120803	120803 - HUAY-HUAY|YAULI|DEPARTAMENTO JUNIN	1,474	179.94	-12.000000	-76.000000	2
1144	120804	120804 - MARCAPOMACOCHA|YAULI|DEPARTAMENTO JUNIN	1,297	888.56	-11.000000	-76.000000	2
1145	120805	120805 - MOROCOCHA|YAULI|DEPARTAMENTO JUNIN	4,262	265.67	-12.000000	-76.000000	2
1146	120806	120806 - PACCHA|YAULI|DEPARTAMENTO JUNIN	1,649	323.69	-11.000000	-76.000000	2
1147	120807	120807 - SANTA BARBARA DE CARHUACAYAN|YAULI|DEPARTAMENTO JUNIN	2,374	646.29	-11.000000	-76.000000	2
1148	120808	120808 - SANTA ROSA DE SACCO|YAULI|DEPARTAMENTO JUNIN	10,413	101.09	-12.000000	-76.000000	2
1149	120809	120809 - SUITUCANCHA|YAULI|DEPARTAMENTO JUNIN	1,014	216.47	-12.000000	-76.000000	2
1150	120810	120810 - YAULI|YAULI|DEPARTAMENTO JUNIN	5,113	424.16	-12.000000	-76.000000	2
1151	120901	120901 - CHUPACA|CHUPACA|DEPARTAMENTO JUNIN	22,407	21.70	-12.000000	-75.000000	2
1152	120902	120902 - AHUAC|CHUPACA|DEPARTAMENTO JUNIN	5932	70.44	-12.000000	-75.000000	2
1153	120903	120903 - CHONGOS BAJO|CHUPACA|DEPARTAMENTO JUNIN	4,006	100.95	-12.000000	-75.000000	2
1154	120904	120904 - HUACHAC|CHUPACA|DEPARTAMENTO JUNIN	4,027	22.01	-12.000000	-75.000000	2
1155	120905	120905 - HUAMANCACA CHICO|CHUPACA|DEPARTAMENTO JUNIN	6126	9.40	-12.000000	-75.000000	2
1156	120906	120906 - SAN JUAN DE YSCOS|CHUPACA|DEPARTAMENTO JUNIN	2,123	24.70	-12.000000	-75.000000	2
1157	120907	120907 - SAN JUAN DE JARPA|CHUPACA|DEPARTAMENTO JUNIN	3,597	137.02	-12.000000	-75.000000	2
1158	120908	120908 - TRES DE DICIEMBRE|CHUPACA|DEPARTAMENTO JUNIN	2,111	14.66	-12.000000	-75.000000	2
1159	120909	120909 - YANACANCHA|CHUPACA|DEPARTAMENTO JUNIN	3,547	743.40	-12.000000	-75.000000	2
1160	130101	130101 - TRUJILLO|TRUJILLO|DEPARTAMENTO LA LIBERTAD	329,127	39.36	-8.000000	-79.000000	2
1161	130102	130102 - EL PORVENIR|TRUJILLO|DEPARTAMENTO LA LIBERTAD	196,333	36.70	-8.000000	-79.000000	2
1162	130103	130103 - FLORENCIA DE MORA|TRUJILLO|DEPARTAMENTO LA LIBERTAD	42,978	1.99	-8.000000	-79.000000	2
1163	130104	130104 - HUANCHACO|TRUJILLO|DEPARTAMENTO LA LIBERTAD	72,237	332.14	-8.000000	-79.000000	2
1164	130105	130105 - LA ESPERANZA|TRUJILLO|DEPARTAMENTO LA LIBERTAD	190,881	15.55	-8.000000	-79.000000	2
1165	130106	130106 - LAREDO|TRUJILLO|DEPARTAMENTO LA LIBERTAD	36,353	335.44	-8.000000	-79.000000	2
1166	130107	130107 - MOCHE|TRUJILLO|DEPARTAMENTO LA LIBERTAD	35,945	25.25	-8.000000	-79.000000	2
1167	130108	130108 - POROTO|TRUJILLO|DEPARTAMENTO LA LIBERTAD	3,127	276.01	-8.000000	-79.000000	2
1168	130109	130109 - SALAVERRY|TRUJILLO|DEPARTAMENTO LA LIBERTAD	19,095	295.88	-8.000000	-79.000000	2
1169	130110	130110 - SIMBAL|TRUJILLO|DEPARTAMENTO LA LIBERTAD	4,433	390.55	-8.000000	-79.000000	2
1170	130111	130111 - VICTOR LARCO HERRERA|TRUJILLO|DEPARTAMENTO LA LIBERTAD	66,607	18.02	-8.000000	-79.000000	2
1171	130201	130201 - ASCOPE|ASCOPE|DEPARTAMENTO LA LIBERTAD	6,676	290.18	-8.000000	-79.000000	2
1172	130202	130202 - CHICAMA|ASCOPE|DEPARTAMENTO LA LIBERTAD	15,796	870.58	-8.000000	-79.000000	2
1173	130203	130203 - CHOCOPE|ASCOPE|DEPARTAMENTO LA LIBERTAD	9,342	95.73	-8.000000	-79.000000	2
1174	130204	130204 - MAGDALENA DE CAO|ASCOPE|DEPARTAMENTO LA LIBERTAD	3,347	163.01	-8.000000	-79.000000	2
1175	130205	130205 - PAIJAN|ASCOPE|DEPARTAMENTO LA LIBERTAD	26,433	79.69	-8.000000	-79.000000	2
1176	130206	130206 - RAZURI|ASCOPE|DEPARTAMENTO LA LIBERTAD	9,358	317.30	-8.000000	-79.000000	2
1177	130207	130207 - SANTIAGO DE CAO|ASCOPE|DEPARTAMENTO LA LIBERTAD	19,896	154.55	-8.000000	-79.000000	2
1178	130208	130208 - CASA GRANDE|ASCOPE|DEPARTAMENTO LA LIBERTAD	31,875	687.60	-8.000000	-79.000000	2
1179	130301	130301 - BOLIVAR|BOLIVAR|DEPARTAMENTO LA LIBERTAD	4,921	740.58	-7.000000	-78.000000	2
1180	130302	130302 - BAMBAMARCA|BOLIVAR|DEPARTAMENTO LA LIBERTAD	3,992	165.20	-7.000000	-78.000000	2
1181	130303	130303 - CONDORMARCA|BOLIVAR|DEPARTAMENTO LA LIBERTAD	2,047	331.26	-8.000000	-78.000000	2
1182	130304	130304 - LONGOTEA|BOLIVAR|DEPARTAMENTO LA LIBERTAD	2,242	192.88	-7.000000	-78.000000	2
1183	130305	130305 - UCHUMARCA|BOLIVAR|DEPARTAMENTO LA LIBERTAD	2,762	190.53	-7.000000	-78.000000	2
1184	130306	130306 - UCUNCHA|BOLIVAR|DEPARTAMENTO LA LIBERTAD	787	98.41	-7.000000	-78.000000	2
1185	130401	130401 - CHEPEN|CHEPEN|DEPARTAMENTO LA LIBERTAD	49,927	287.34	-7.000000	-79.000000	2
1186	130402	130402 - PACANGA|CHEPEN|DEPARTAMENTO LA LIBERTAD	24,913	583.93	-7.000000	-79.000000	2
1187	130403	130403 - PUEBLO NUEVO|CHEPEN|DEPARTAMENTO LA LIBERTAD	15,458	271.16	-7.000000	-80.000000	2
1188	130501	130501 - JULCAN|JULCAN|DEPARTAMENTO LA LIBERTAD	11,630	208.49	-8.000000	-78.000000	2
1189	130502	130502 - CALAMARCA|JULCAN|DEPARTAMENTO LA LIBERTAD	5,604	207.57	-8.000000	-78.000000	2
1190	130503	130503 - CARABAMBA|JULCAN|DEPARTAMENTO LA LIBERTAD	6,418	254.28	-8.000000	-79.000000	2
1191	130504	130504 - HUASO|JULCAN|DEPARTAMENTO LA LIBERTAD	7,304	431.05	-8.000000	-78.000000	2
1192	130601	130601 - OTUZCO|OTUZCO|DEPARTAMENTO LA LIBERTAD	28,048	444.13	-8.000000	-79.000000	2
1193	130602	130602 - AGALLPAMPA|OTUZCO|DEPARTAMENTO LA LIBERTAD	9,997	258.56	-8.000000	-79.000000	2
1194	130604	130604 - CHARAT|OTUZCO|DEPARTAMENTO LA LIBERTAD	2,814	68.89	-8.000000	-78.000000	2
1195	130605	130605 - HUARANCHAL|OTUZCO|DEPARTAMENTO LA LIBERTAD	5,138	149.65	-8.000000	-78.000000	2
1196	130606	130606 - LA CUESTA|OTUZCO|DEPARTAMENTO LA LIBERTAD	690	39.25	-8.000000	-79.000000	2
1197	130608	130608 - MACHE|OTUZCO|DEPARTAMENTO LA LIBERTAD	3,129	37.32	-8.000000	-79.000000	2
1198	130610	130610 - PARANDAY|OTUZCO|DEPARTAMENTO LA LIBERTAD	746	21.46	-8.000000	-79.000000	2
1199	130611	130611 - SALPO|OTUZCO|DEPARTAMENTO LA LIBERTAD	6,142	192.74	-8.000000	-79.000000	2
1200	130613	130613 - SINSICAP|OTUZCO|DEPARTAMENTO LA LIBERTAD	8,808	452.95	-8.000000	-79.000000	2
1201	130614	130614 - USQUIL|OTUZCO|DEPARTAMENTO LA LIBERTAD	27,986	445.82	-8.000000	-78.000000	2
1202	130701	130701 - SAN PEDRO DE LLOC|PACASMAYO|DEPARTAMENTO LA LIBERTAD	17,017	697.01	-7.000000	-80.000000	2
1203	130702	130702 - GUADALUPE|PACASMAYO|DEPARTAMENTO LA LIBERTAD	45,031	165.37	-7.000000	-79.000000	2
1204	130703	130703 - JEQUETEPEQUE|PACASMAYO|DEPARTAMENTO LA LIBERTAD	3,997	50.98	-7.000000	-80.000000	2
1205	130704	130704 - PACASMAYO|PACASMAYO|DEPARTAMENTO LA LIBERTAD	28,458	30.84	-7.000000	-80.000000	2
1206	130705	130705 - SAN JOSE|PACASMAYO|DEPARTAMENTO LA LIBERTAD	12,790	181.06	-7.000000	-79.000000	2
1207	130801	130801 - TAYABAMBA|PATAZ|DEPARTAMENTO LA LIBERTAD	15,100	339.13	-8.000000	-77.000000	2
1208	130802	130802 - BULDIBUYO|PATAZ|DEPARTAMENTO LA LIBERTAD	3,823	227.39	-8.000000	-77.000000	2
1209	130803	130803 - CHILLIA|PATAZ|DEPARTAMENTO LA LIBERTAD	14,060	300.04	-8.000000	-78.000000	2
1210	130804	130804 - HUANCASPATA|PATAZ|DEPARTAMENTO LA LIBERTAD	6,532	247.48	-8.000000	-77.000000	2
1211	130805	130805 - HUAYLILLAS|PATAZ|DEPARTAMENTO LA LIBERTAD	3,691	89.73	-8.000000	-77.000000	2
1212	130806	130806 - HUAYO|PATAZ|DEPARTAMENTO LA LIBERTAD	4,527	124.63	-8.000000	-78.000000	2
1213	130807	130807 - ONGON|PATAZ|DEPARTAMENTO LA LIBERTAD	1,817	1,394.89	-8.000000	-77.000000	2
1214	130808	130808 - PARCOY|PATAZ|DEPARTAMENTO LA LIBERTAD	22,442	304.99	-8.000000	-77.000000	2
1215	130809	130809 - PATAZ|PATAZ|DEPARTAMENTO LA LIBERTAD	9,408	467.44	-8.000000	-78.000000	2
1216	130810	130810 - PIAS|PATAZ|DEPARTAMENTO LA LIBERTAD	1,293	371.67	-8.000000	-78.000000	2
1217	130811	130811 - SANTIAGO DE CHALLAS|PATAZ|DEPARTAMENTO LA LIBERTAD	2,520	129.44	-8.000000	-77.000000	2
1218	130812	130812 - TAURIJA|PATAZ|DEPARTAMENTO LA LIBERTAD	3,067	130.09	-8.000000	-77.000000	2
1219	130813	130813 - URPAY|PATAZ|DEPARTAMENTO LA LIBERTAD	2,809	99.61	-8.000000	-77.000000	2
1220	130901	130901 - HUAMACHUCO|SANCHEZ CARRION|DEPARTAMENTO LA LIBERTAD	65,142	424.13	-8.000000	-78.000000	2
1221	130902	130902 - CHUGAY|SANCHEZ CARRION|DEPARTAMENTO LA LIBERTAD	19,316	416.31	-8.000000	-78.000000	2
1222	130903	130903 - COCHORCO|SANCHEZ CARRION|DEPARTAMENTO LA LIBERTAD	9,588	258.04	-8.000000	-78.000000	2
1223	130904	130904 - CURGOS|SANCHEZ CARRION|DEPARTAMENTO LA LIBERTAD	8,712	99.50	-8.000000	-78.000000	2
1224	130905	130905 - MARCABAL|SANCHEZ CARRION|DEPARTAMENTO LA LIBERTAD	17,296	229.57	-8.000000	-78.000000	2
1225	130906	130906 - SANAGORAN|SANCHEZ CARRION|DEPARTAMENTO LA LIBERTAD	15,424	324.38	-8.000000	-78.000000	2
1226	130907	130907 - SARIN|SANCHEZ CARRION|DEPARTAMENTO LA LIBERTAD	10,241	340.08	-8.000000	-78.000000	2
1227	130908	130908 - SARTIMBAMBA|SANCHEZ CARRION|DEPARTAMENTO LA LIBERTAD	14,090	394.37	-8.000000	-78.000000	2
1228	131001	131001 - SANTIAGO DE CHUCO|SANTIAGO DE CHUCO|DEPARTAMENTO LA LIBERTAD	20,781	1,073.63	-8.000000	-78.000000	2
1229	131002	131002 - ANGASMARCA|SANTIAGO DE CHUCO|DEPARTAMENTO LA LIBERTAD	7,592	153.45	-8.000000	-78.000000	2
1230	131003	131003 - CACHICADAN|SANTIAGO DE CHUCO|DEPARTAMENTO LA LIBERTAD	8,320	266.50	-8.000000	-78.000000	2
1231	131004	131004 - MOLLEBAMBA|SANTIAGO DE CHUCO|DEPARTAMENTO LA LIBERTAD	2,411	69.69	-8.000000	-78.000000	2
1232	131005	131005 - MOLLEPATA|SANTIAGO DE CHUCO|DEPARTAMENTO LA LIBERTAD	2,682	71.20	-8.000000	-78.000000	2
1233	131006	131006 - QUIRUVILCA|SANTIAGO DE CHUCO|DEPARTAMENTO LA LIBERTAD	14,549	549.14	-8.000000	-78.000000	2
1234	131007	131007 - SANTA CRUZ DE CHUCA|SANTIAGO DE CHUCO|DEPARTAMENTO LA LIBERTAD	3,222	165.12	-8.000000	-78.000000	2
1235	131008	131008 - SITABAMBA|SANTIAGO DE CHUCO|DEPARTAMENTO LA LIBERTAD	3,367	310.23	-8.000000	-78.000000	2
1236	131101	131101 - CASCAS|GRAN CHIMU|DEPARTAMENTO LA LIBERTAD	14,386	465.67	-7.000000	-79.000000	2
1237	131102	131102 - LUCMA|GRAN CHIMU|DEPARTAMENTO LA LIBERTAD	7,210	280.38	-8.000000	-79.000000	2
1238	131103	131103 - COMPIN|GRAN CHIMU|DEPARTAMENTO LA LIBERTAD	2,054	300.25	-8.000000	-79.000000	2
1239	131104	131104 - SAYAPULLO|GRAN CHIMU|DEPARTAMENTO LA LIBERTAD	7,994	238.47	-8.000000	-78.000000	2
1240	131201	131201 - VIRU|VIRU|DEPARTAMENTO LA LIBERTAD	71,152	1,072.95	-8.000000	-79.000000	2
1241	131202	131202 - CHAO|VIRU|DEPARTAMENTO LA LIBERTAD	42,779	1,736.87	-9.000000	-79.000000	2
1242	131203	131203 - GUADALUPITO|VIRU|DEPARTAMENTO LA LIBERTAD	10,166	404.72	-9.000000	-79.000000	2
1243	140101	140101 - CHICLAYO|CHICLAYO|DEPARTAMENTO LAMBAYEQUE	298,467	50.35	-7.000000	-80.000000	2
1244	140102	140102 - CHONGOYAPE|CHICLAYO|DEPARTAMENTO LAMBAYEQUE	18,101	712.00	-7.000000	-79.000000	2
1245	140103	140103 - ETEN|CHICLAYO|DEPARTAMENTO LAMBAYEQUE	10,599	84.78	-7.000000	-80.000000	2
1246	140104	140104 - ETEN PUERTO|CHICLAYO|DEPARTAMENTO LAMBAYEQUE	2,160	14.48	-7.000000	-80.000000	2
1247	140105	140105 - JOSE LEONARDO ORTIZ|CHICLAYO|DEPARTAMENTO LAMBAYEQUE	199,144	28.22	-7.000000	-80.000000	2
1248	140106	140106 - LA VICTORIA|CHICLAYO|DEPARTAMENTO LAMBAYEQUE	93,069	29.36	-7.000000	-80.000000	2
1249	140107	140107 - LAGUNAS|CHICLAYO|DEPARTAMENTO LAMBAYEQUE	10,436	429.27	-7.000000	-80.000000	2
1250	140108	140108 - MONSEFU|CHICLAYO|DEPARTAMENTO LAMBAYEQUE	32,314	44.94	-7.000000	-80.000000	2
1251	140109	140109 - NUEVA ARICA|CHICLAYO|DEPARTAMENTO LAMBAYEQUE	2,331	208.63	-7.000000	-79.000000	2
1252	140110	140110 - OYOTUN|CHICLAYO|DEPARTAMENTO LAMBAYEQUE	9,879	455.40	-7.000000	-79.000000	2
1253	140111	140111 - PICSI|CHICLAYO|DEPARTAMENTO LAMBAYEQUE	9,965	56.92	-7.000000	-80.000000	2
1254	140112	140112 - PIMENTEL|CHICLAYO|DEPARTAMENTO LAMBAYEQUE	46,075	66.53	-7.000000	-80.000000	2
1255	140113	140113 - REQUE|CHICLAYO|DEPARTAMENTO LAMBAYEQUE	15,386	47.03	-7.000000	-80.000000	2
1256	140114	140114 - SANTA ROSA|CHICLAYO|DEPARTAMENTO LAMBAYEQUE	13,030	14.09	-7.000000	-80.000000	2
1257	140115	140115 - SAÑA|CHICLAYO|DEPARTAMENTO LAMBAYEQUE	12,397	313.90	-7.000000	-80.000000	2
1258	140116	140116 - CAYALTI|CHICLAYO|DEPARTAMENTO LAMBAYEQUE	15,915	162.86	-7.000000	-80.000000	2
1259	140117	140117 - PATAPO|CHICLAYO|DEPARTAMENTO LAMBAYEQUE	22,843	182.81	-7.000000	-80.000000	2
1260	140118	140118 - POMALCA|CHICLAYO|DEPARTAMENTO LAMBAYEQUE	25,831	80.35	-7.000000	-80.000000	2
1261	140119	140119 - PUCALA|CHICLAYO|DEPARTAMENTO LAMBAYEQUE	8,958	175.81	-7.000000	-80.000000	2
1262	140120	140120 - TUMAN|CHICLAYO|DEPARTAMENTO LAMBAYEQUE	30,713	130.34	-7.000000	-80.000000	2
1263	140201	140201 - FERREÑAFE|FERREÑAFE|DEPARTAMENTO LAMBAYEQUE	35,919	62.18	-7.000000	-80.000000	2
1264	140202	140202 - CAÑARIS|FERREÑAFE|DEPARTAMENTO LAMBAYEQUE	14,787	284.88	-6.000000	-79.000000	2
1265	140203	140203 - INCAHUASI|FERREÑAFE|DEPARTAMENTO LAMBAYEQUE	15,733	443.91	-6.000000	-79.000000	2
1266	140204	140204 - MANUEL ANTONIO MESONES MURO|FERREÑAFE|DEPARTAMENTO LAMBAYEQUE	4,254	200.57	-7.000000	-80.000000	2
1267	140205	140205 - PITIPO|FERREÑAFE|DEPARTAMENTO LAMBAYEQUE	24,214	558.18	-7.000000	-80.000000	2
1268	140206	140206 - PUEBLO NUEVO|FERREÑAFE|DEPARTAMENTO LAMBAYEQUE	13,619	28.88	-7.000000	-80.000000	2
1269	140301	140301 - LAMBAYEQUE|LAMBAYEQUE|DEPARTAMENTO LAMBAYEQUE	79,914	330.73	-7.000000	-80.000000	2
1270	140302	140302 - CHOCHOPE|LAMBAYEQUE|DEPARTAMENTO LAMBAYEQUE	1,122	79.27	-6.000000	-80.000000	2
1271	140303	140303 - ILLIMO|LAMBAYEQUE|DEPARTAMENTO LAMBAYEQUE	9,415	24.37	-6.000000	-80.000000	2
1272	140304	140304 - JAYANCA|LAMBAYEQUE|DEPARTAMENTO LAMBAYEQUE	18,039	680.96	-6.000000	-80.000000	2
1273	140305	140305 - MOCHUMI|LAMBAYEQUE|DEPARTAMENTO LAMBAYEQUE	19,467	103.70	-7.000000	-80.000000	2
1274	140306	140306 - MORROPE|LAMBAYEQUE|DEPARTAMENTO LAMBAYEQUE	47,455	1,041.66	-7.000000	-80.000000	2
1275	140307	140307 - MOTUPE|LAMBAYEQUE|DEPARTAMENTO LAMBAYEQUE	26,985	557.37	-6.000000	-80.000000	2
1276	140308	140308 - OLMOS|LAMBAYEQUE|DEPARTAMENTO LAMBAYEQUE	41,587	5,583.47	-6.000000	-80.000000	2
1277	140309	140309 - PACORA|LAMBAYEQUE|DEPARTAMENTO LAMBAYEQUE	7,299	87.79	-6.000000	-80.000000	2
1278	140310	140310 - SALAS|LAMBAYEQUE|DEPARTAMENTO LAMBAYEQUE	13,056	991.80	-6.000000	-80.000000	2
1279	140311	140311 - SAN JOSE|LAMBAYEQUE|DEPARTAMENTO LAMBAYEQUE	16,851	46.73	-7.000000	-80.000000	2
1280	140312	140312 - TUCUME|LAMBAYEQUE|DEPARTAMENTO LAMBAYEQUE	23,288	67.00	-7.000000	-80.000000	2
1281	150101	150101 - LIMA|LIMA|DEPARTAMENTO LIMA	269,858	21.98	-12.000000	-77.000000	2
1282	150102	150102 - ANCON|LIMA|DEPARTAMENTO LIMA	42,124	285.45	-12.000000	-77.000000	2
1283	150103	150103 - ATE|LIMA|DEPARTAMENTO LIMA	672,160	77.72	-12.000000	-77.000000	2
1284	150104	150104 - BARRANCO|LIMA|DEPARTAMENTO LIMA	29,424	3.33	-12.000000	-77.000000	2
1285	150105	150105 - BREÑA|LIMA|DEPARTAMENTO LIMA	75,882	3.22	-12.000000	-77.000000	2
1286	150106	150106 - CARABAYLLO|LIMA|DEPARTAMENTO LIMA	322,936	303.31	-12.000000	-77.000000	2
1287	150107	150107 - CHACLACAYO|LIMA|DEPARTAMENTO LIMA	44,890	39.50	-12.000000	-77.000000	2
1288	150108	150108 - CHORRILLOS|LIMA|DEPARTAMENTO LIMA	341,322	38.94	-12.000000	-77.000000	2
1289	150109	150109 - CIENEGUILLA|LIMA|DEPARTAMENTO LIMA	50,486	240.33	-12.000000	-77.000000	2
1290	150110	150110 - COMAS|LIMA|DEPARTAMENTO LIMA	545,685	48.75	-12.000000	-77.000000	2
1291	150111	150111 - EL AGUSTINO|LIMA|DEPARTAMENTO LIMA	198,366	12.54	-12.000000	-77.000000	2
1292	150112	150112 - INDEPENDENCIA|LIMA|DEPARTAMENTO LIMA	223,827	14.56	-12.000000	-77.000000	2
1293	150113	150113 - JESUS MARIA|LIMA|DEPARTAMENTO LIMA	72,804	4.57	-12.000000	-77.000000	2
1294	150114	150114 - LA MOLINA|LIMA|DEPARTAMENTO LIMA	182,603	65.75	-12.000000	-77.000000	2
1295	150115	150115 - LA VICTORIA|LIMA|DEPARTAMENTO LIMA	169,270	8.74	-12.000000	-77.000000	2
1296	150116	150116 - LINCE|LIMA|DEPARTAMENTO LIMA	49,833	3.03	-12.000000	-77.000000	2
1297	150117	150117 - LOS OLIVOS|LIMA|DEPARTAMENTO LIMA	390,742	18.25	-12.000000	-77.000000	2
1298	150118	150118 - LURIGANCHO|LIMA|DEPARTAMENTO LIMA	232,902	236.47	-12.000000	-77.000000	2
1299	150119	150119 - LURIN|LIMA|DEPARTAMENTO LIMA	90,818	180.26	-12.000000	-77.000000	2
1300	150120	150120 - MAGDALENA DEL MAR|LIMA|DEPARTAMENTO LIMA	55,786	3.61	-12.000000	-77.000000	2
1301	150121	150121 - PUEBLO LIBRE|LIMA|DEPARTAMENTO LIMA	77,322	4.38	-12.000000	-77.000000	2
1302	150122	150122 - MIRAFLORES|LIMA|DEPARTAMENTO LIMA	82,898	9.62	-12.000000	-77.000000	2
1303	150123	150123 - PACHACAMAC|LIMA|DEPARTAMENTO LIMA	139,067	160.23	-12.000000	-77.000000	2
1304	150124	150124 - PUCUSANA|LIMA|DEPARTAMENTO LIMA	18,284	37.39	-12.000000	-77.000000	2
1305	150125	150125 - PUENTE PIEDRA|LIMA|DEPARTAMENTO LIMA	378,910	72.81	-12.000000	-77.000000	2
1306	150126	150126 - PUNTA HERMOSA|LIMA|DEPARTAMENTO LIMA	8,104	119.50	-12.000000	-77.000000	2
1307	150127	150127 - PUNTA NEGRA|LIMA|DEPARTAMENTO LIMA	8,500	130.50	-12.000000	-77.000000	2
1308	150128	150128 - RIMAC|LIMA|DEPARTAMENTO LIMA	165,451	11.87	-12.000000	-77.000000	2
1309	150129	150129 - SAN BARTOLO|LIMA|DEPARTAMENTO LIMA	8,200	45.01	-12.000000	-77.000000	2
1310	150130	150130 - SAN BORJA|LIMA|DEPARTAMENTO LIMA	114,479	9.96	-12.000000	-77.000000	2
1311	150131	150131 - SAN ISIDRO|LIMA|DEPARTAMENTO LIMA	54,298	11.10	-12.000000	-77.000000	2
1312	150132	150132 - SAN JUAN DE LURIGANCHO|LIMA|DEPARTAMENTO LIMA	1,156,300	131.25	-12.000000	-77.000000	2
1313	150133	150133 - SAN JUAN DE MIRAFLORES|LIMA|DEPARTAMENTO LIMA	422,389	22.97	-12.000000	-77.000000	2
1314	150134	150134 - SAN LUIS|LIMA|DEPARTAMENTO LIMA	59,377	3.49	-12.000000	-77.000000	2
1315	150135	150135 - SAN MARTIN DE PORRES|LIMA|DEPARTAMENTO LIMA	741,417	36.82	-12.000000	-77.000000	2
1316	150136	150136 - SAN MIGUEL|LIMA|DEPARTAMENTO LIMA	139,399	10.72	-12.000000	-77.000000	2
1317	150137	150137 - SANTA ANITA|LIMA|DEPARTAMENTO LIMA	242,026	10.69	-12.000000	-77.000000	2
1318	150138	150138 - SANTA MARIA DEL MAR|LIMA|DEPARTAMENTO LIMA	1,721	9.81	-12.000000	-77.000000	2
1319	150139	150139 - SANTA ROSA|LIMA|DEPARTAMENTO LIMA	20,112	21.35	-12.000000	-77.000000	2
1320	150140	150140 - SANTIAGO DE SURCO|LIMA|DEPARTAMENTO LIMA	363,183	35.89	-12.000000	-77.000000	2
1321	150141	150141 - SURQUILLO|LIMA|DEPARTAMENTO LIMA	92,908	3.46	-12.000000	-77.000000	2
1322	150142	150142 - VILLA EL SALVADOR|LIMA|DEPARTAMENTO LIMA	489,583	35.46	-12.000000	-77.000000	2
1323	150143	150143 - VILLA MARIA DEL TRIUNFO|LIMA|DEPARTAMENTO LIMA	473,036	70.57	-12.000000	-77.000000	2
1324	150201	150201 - BARRANCA|BARRANCA|DEPARTAMENTO LIMA	73,485	158.82	-11.000000	-78.000000	2
1325	150202	150202 - PARAMONGA|BARRANCA|DEPARTAMENTO LIMA	22,373	408.59	-11.000000	-78.000000	2
1326	150203	150203 - PATIVILCA|BARRANCA|DEPARTAMENTO LIMA	20,032	278.64	-11.000000	-78.000000	2
1327	150204	150204 - SUPE|BARRANCA|DEPARTAMENTO LIMA	23,345	512.92	-11.000000	-78.000000	2
1328	150205	150205 - SUPE PUERTO|BARRANCA|DEPARTAMENTO LIMA	11,898	11.51	-11.000000	-78.000000	2
1329	150301	150301 - CAJATAMBO|CAJATAMBO|DEPARTAMENTO LIMA	2,260	567.96	-10.000000	-77.000000	2
1330	150302	150302 - COPA|CAJATAMBO|DEPARTAMENTO LIMA	803	212.16	-10.000000	-77.000000	2
1331	150303	150303 - GORGOR|CAJATAMBO|DEPARTAMENTO LIMA	2,774	309.95	-11.000000	-77.000000	2
1332	150304	150304 - HUANCAPON|CAJATAMBO|DEPARTAMENTO LIMA	982	146.10	-11.000000	-77.000000	2
1333	150305	150305 - MANAS|CAJATAMBO|DEPARTAMENTO LIMA	982	279.04	-11.000000	-77.000000	2
1334	150401	150401 - CANTA|CANTA|DEPARTAMENTO LIMA	2,900	123.09	-11.000000	-77.000000	2
1335	150402	150402 - ARAHUAY|CANTA|DEPARTAMENTO LIMA	796	134.29	-12.000000	-77.000000	2
1336	150403	150403 - HUAMANTANGA|CANTA|DEPARTAMENTO LIMA	1,349	488.09	-11.000000	-77.000000	2
1337	150404	150404 - HUAROS|CANTA|DEPARTAMENTO LIMA	785	333.45	-11.000000	-77.000000	2
1338	150405	150405 - LACHAQUI|CANTA|DEPARTAMENTO LIMA	904	137.87	-12.000000	-77.000000	2
1339	150406	150406 - SAN BUENAVENTURA|CANTA|DEPARTAMENTO LIMA	567	106.26	-11.000000	-77.000000	2
1340	150407	150407 - SANTA ROSA DE QUIVES|CANTA|DEPARTAMENTO LIMA	8,388	408.11	-12.000000	-77.000000	2
1341	150501	150501 - SAN VICENTE DE CAÑETE|CAÑETE|DEPARTAMENTO LIMA	58,091	513.15	-13.000000	-76.000000	2
1342	150502	150502 - ASIA|CAÑETE|DEPARTAMENTO LIMA	9,902	277.36	-13.000000	-77.000000	2
1343	150503	150503 - CALANGO|CAÑETE|DEPARTAMENTO LIMA	2,434	530.89	-13.000000	-77.000000	2
1344	150504	150504 - CERRO AZUL|CAÑETE|DEPARTAMENTO LIMA	8,489	105.08	-13.000000	-76.000000	2
1345	150505	150505 - CHILCA|CAÑETE|DEPARTAMENTO LIMA	16,350	475.47	-13.000000	-77.000000	2
1346	150506	150506 - COAYLLO|CAÑETE|DEPARTAMENTO LIMA	1,097	590.99	-13.000000	-76.000000	2
1347	150507	150507 - IMPERIAL|CAÑETE|DEPARTAMENTO LIMA	41,037	53.16	-13.000000	-76.000000	2
1348	150508	150508 - LUNAHUANA|CAÑETE|DEPARTAMENTO LIMA	4,921	500.33	-13.000000	-76.000000	2
1349	150509	150509 - MALA|CAÑETE|DEPARTAMENTO LIMA	35,929	129.31	-13.000000	-77.000000	2
1350	150510	150510 - NUEVO IMPERIAL|CAÑETE|DEPARTAMENTO LIMA	24,623	329.30	-13.000000	-76.000000	2
1351	150511	150511 - PACARAN|CAÑETE|DEPARTAMENTO LIMA	1,842	258.72	-13.000000	-76.000000	2
1352	150512	150512 - QUILMANA|CAÑETE|DEPARTAMENTO LIMA	15,823	437.40	-13.000000	-76.000000	2
1353	150513	150513 - SAN ANTONIO|CAÑETE|DEPARTAMENTO LIMA	4,371	37.15	-13.000000	-77.000000	2
1354	150514	150514 - SAN LUIS|CAÑETE|DEPARTAMENTO LIMA	13,420	38.53	-13.000000	-76.000000	2
1355	150515	150515 - SANTA CRUZ DE FLORES|CAÑETE|DEPARTAMENTO LIMA	2,898	100.06	-13.000000	-77.000000	2
1356	150516	150516 - ZUÑIGA|CAÑETE|DEPARTAMENTO LIMA	1,912	198.01	-13.000000	-76.000000	2
1357	150601	150601 - HUARAL|HUARAL|DEPARTAMENTO LIMA	104,610	640.76	-11.000000	-77.000000	2
1358	150602	150602 - ATAVILLOS ALTO|HUARAL|DEPARTAMENTO LIMA	626	347.69	-11.000000	-77.000000	2
1359	150603	150603 - ATAVILLOS BAJO|HUARAL|DEPARTAMENTO LIMA	1,135	164.89	-11.000000	-77.000000	2
1360	150604	150604 - AUCALLAMA|HUARAL|DEPARTAMENTO LIMA	20,446	729.41	-12.000000	-77.000000	2
1361	150605	150605 - CHANCAY|HUARAL|DEPARTAMENTO LIMA	65,014	150.11	-12.000000	-77.000000	2
1362	150606	150606 - IHUARI|HUARAL|DEPARTAMENTO LIMA	2,344	467.67	-11.000000	-77.000000	2
1363	150607	150607 - LAMPIAN|HUARAL|DEPARTAMENTO LIMA	389	144.97	-11.000000	-77.000000	2
1364	150608	150608 - PACARAOS|HUARAL|DEPARTAMENTO LIMA	385	294.04	-11.000000	-77.000000	2
1365	150609	150609 - SAN MIGUEL DE ACOS|HUARAL|DEPARTAMENTO LIMA	777	48.16	-11.000000	-77.000000	2
1366	150610	150610 - SANTA CRUZ DE ANDAMARCA|HUARAL|DEPARTAMENTO LIMA	1,469	216.92	-11.000000	-77.000000	2
1367	150611	150611 - SUMBILCA|HUARAL|DEPARTAMENTO LIMA	948	259.38	-11.000000	-77.000000	2
1368	150612	150612 - VEINTISIETE DE NOVIEMBRE|HUARAL|DEPARTAMENTO LIMA	414	204.27	-11.000000	-77.000000	2
1369	150701	150701 - MATUCANA|HUAROCHIRI|DEPARTAMENTO LIMA	3,584	179.44	-12.000000	-76.000000	2
1370	150702	150702 - ANTIOQUIA|HUAROCHIRI|DEPARTAMENTO LIMA	1,246	387.98	-12.000000	-77.000000	2
1371	150703	150703 - CALLAHUANCA|HUAROCHIRI|DEPARTAMENTO LIMA	4,357	57.47	-12.000000	-77.000000	2
1372	150704	150704 - CARAMPOMA|HUAROCHIRI|DEPARTAMENTO LIMA	1,907	234.21	-12.000000	-77.000000	2
1373	150705	150705 - CHICLA|HUAROCHIRI|DEPARTAMENTO LIMA	7,881	244.10	-12.000000	-76.000000	2
1374	150706	150706 - CUENCA|HUAROCHIRI|DEPARTAMENTO LIMA	397	60.02	-12.000000	-76.000000	2
1375	150707	150707 - HUACHUPAMPA|HUAROCHIRI|DEPARTAMENTO LIMA	3,003	76.02	-12.000000	-77.000000	2
1376	150708	150708 - HUANZA|HUAROCHIRI|DEPARTAMENTO LIMA	2,851	227.01	-12.000000	-77.000000	2
1377	150709	150709 - HUAROCHIRI|HUAROCHIRI|DEPARTAMENTO LIMA	1,251	249.09	-12.000000	-76.000000	2
1378	150710	150710 - LAHUAYTAMBO|HUAROCHIRI|DEPARTAMENTO LIMA	651	81.88	-12.000000	-76.000000	2
1379	150711	150711 - LANGA|HUAROCHIRI|DEPARTAMENTO LIMA	822	80.99	-12.000000	-76.000000	2
1380	150712	150712 - LARAOS|HUAROCHIRI|DEPARTAMENTO LIMA	2,452	104.51	-12.000000	-77.000000	2
1381	150713	150713 - MARIATANA|HUAROCHIRI|DEPARTAMENTO LIMA	1,325	168.63	-12.000000	-76.000000	2
1382	150714	150714 - RICARDO PALMA|HUAROCHIRI|DEPARTAMENTO LIMA	6,358	34.59	-12.000000	-77.000000	2
1383	150715	150715 - SAN ANDRES DE TUPICOCHA|HUAROCHIRI|DEPARTAMENTO LIMA	1,272	83.35	-12.000000	-76.000000	2
1384	150716	150716 - SAN ANTONIO|HUAROCHIRI|DEPARTAMENTO LIMA	5785	563.59	-12.000000	-77.000000	2
1385	150717	150717 - SAN BARTOLOME|HUAROCHIRI|DEPARTAMENTO LIMA	2,409	43.91	-12.000000	-77.000000	2
1386	150718	150718 - SAN DAMIAN|HUAROCHIRI|DEPARTAMENTO LIMA	1,137	343.22	-12.000000	-76.000000	2
1387	150719	150719 - SAN JUAN DE IRIS|HUAROCHIRI|DEPARTAMENTO LIMA	1,891	124.31	-12.000000	-77.000000	2
1388	150720	150720 - SAN JUAN DE TANTARANCHE|HUAROCHIRI|DEPARTAMENTO LIMA	477	137.16	-12.000000	-76.000000	2
1389	150721	150721 - SAN LORENZO DE QUINTI|HUAROCHIRI|DEPARTAMENTO LIMA	1,547	467.58	-12.000000	-76.000000	2
1390	150722	150722 - SAN MATEO|HUAROCHIRI|DEPARTAMENTO LIMA	5,120	425.60	-12.000000	-76.000000	2
1391	150723	150723 - SAN MATEO DE OTAO|HUAROCHIRI|DEPARTAMENTO LIMA	1,599	123.91	-12.000000	-77.000000	2
1392	150724	150724 - SAN PEDRO DE CASTA|HUAROCHIRI|DEPARTAMENTO LIMA	1,325	79.91	-12.000000	-77.000000	2
1393	150725	150725 - SAN PEDRO DE HUANCAYRE|HUAROCHIRI|DEPARTAMENTO LIMA	248	41.75	-12.000000	-76.000000	2
1394	150726	150726 - SANGALLAYA|HUAROCHIRI|DEPARTAMENTO LIMA	569	81.92	-12.000000	-76.000000	2
1395	150727	150727 - SANTA CRUZ DE COCACHACRA|HUAROCHIRI|DEPARTAMENTO LIMA	2,541	41.50	-12.000000	-77.000000	2
1396	150728	150728 - SANTA EULALIA|HUAROCHIRI|DEPARTAMENTO LIMA	12,476	111.12	-12.000000	-77.000000	2
1397	150729	150729 - SANTIAGO DE ANCHUCAYA|HUAROCHIRI|DEPARTAMENTO LIMA	526	94.01	-12.000000	-76.000000	2
1398	150730	150730 - SANTIAGO DE TUNA|HUAROCHIRI|DEPARTAMENTO LIMA	762	54.25	-12.000000	-77.000000	2
1399	150731	150731 - SANTO DOMINGO DE LOS OLLEROS|HUAROCHIRI|DEPARTAMENTO LIMA	5,026	552.32	-12.000000	-77.000000	2
1400	150732	150732 - SURCO|HUAROCHIRI|DEPARTAMENTO LIMA	1,973	102.58	-12.000000	-76.000000	2
1401	150801	150801 - HUACHO|HUAURA|DEPARTAMENTO LIMA	60,196	717.02	-11.000000	-78.000000	2
1402	150802	150802 - AMBAR|HUAURA|DEPARTAMENTO LIMA	2,761	929.68	-11.000000	-77.000000	2
1403	150803	150803 - CALETA DE CARQUIN|HUAURA|DEPARTAMENTO LIMA	7,055	2.04	-11.000000	-78.000000	2
1404	150804	150804 - CHECRAS|HUAURA|DEPARTAMENTO LIMA	1,864	166.37	-11.000000	-77.000000	2
1405	150805	150805 - HUALMAY|HUAURA|DEPARTAMENTO LIMA	29,448	5.81	-11.000000	-78.000000	2
1406	150806	150806 - HUAURA|HUAURA|DEPARTAMENTO LIMA	36,793	484.43	-11.000000	-78.000000	2
1407	150807	150807 - LEONCIO PRADO|HUAURA|DEPARTAMENTO LIMA	2,004	300.13	-11.000000	-77.000000	2
1408	150808	150808 - PACCHO|HUAURA|DEPARTAMENTO LIMA	2,225	229.25	-11.000000	-77.000000	2
1409	150809	150809 - SANTA LEONOR|HUAURA|DEPARTAMENTO LIMA	1,462	375.49	-11.000000	-77.000000	2
1410	150810	150810 - SANTA MARIA|HUAURA|DEPARTAMENTO LIMA	35,132	127.51	-11.000000	-78.000000	2
1411	150811	150811 - SAYAN|HUAURA|DEPARTAMENTO LIMA	24,941	1,310.77	-11.000000	-77.000000	2
1412	150812	150812 - VEGUETA|HUAURA|DEPARTAMENTO LIMA	23,091	253.70	-11.000000	-78.000000	2
1413	150901	150901 - OYON|OYON|DEPARTAMENTO LIMA	15,066	890.43	-11.000000	-77.000000	2
1414	150902	150902 - ANDAJES|OYON|DEPARTAMENTO LIMA	1,058	148.18	-11.000000	-77.000000	2
1415	150903	150903 - CAUJUL|OYON|DEPARTAMENTO LIMA	1,076	105.50	-11.000000	-77.000000	2
1416	150904	150904 - COCHAMARCA|OYON|DEPARTAMENTO LIMA	1,653	265.55	-11.000000	-77.000000	2
1417	150905	150905 - NAVAN|OYON|DEPARTAMENTO LIMA	1,235	227.16	-11.000000	-77.000000	2
1418	150906	150906 - PACHANGARA|OYON|DEPARTAMENTO LIMA	3,485	252.05	-11.000000	-77.000000	2
1419	151001	151001 - YAUYOS|YAUYOS|DEPARTAMENTO LIMA	2,905	327.17	-12.000000	-76.000000	2
1420	151002	151002 - ALIS|YAUYOS|DEPARTAMENTO LIMA	1,233	142.06	-12.000000	-76.000000	2
1421	151003	151003 - AYAUCA|YAUYOS|DEPARTAMENTO LIMA	2,293	438.79	-13.000000	-76.000000	2
1422	151004	151004 - AYAVIRI|YAUYOS|DEPARTAMENTO LIMA	675	238.83	-12.000000	-76.000000	2
1423	151005	151005 - AZANGARO|YAUYOS|DEPARTAMENTO LIMA	519	79.84	-13.000000	-76.000000	2
1424	151006	151006 - CACRA|YAUYOS|DEPARTAMENTO LIMA	383	213.79	-13.000000	-76.000000	2
1425	151007	151007 - CARANIA|YAUYOS|DEPARTAMENTO LIMA	378	122.13	-12.000000	-76.000000	2
1426	151008	151008 - CATAHUASI|YAUYOS|DEPARTAMENTO LIMA	943	123.86	-13.000000	-76.000000	2
1427	151009	151009 - CHOCOS|YAUYOS|DEPARTAMENTO LIMA	1,236	213.37	-13.000000	-76.000000	2
1428	151010	151010 - COCHAS|YAUYOS|DEPARTAMENTO LIMA	449	27.73	-12.000000	-76.000000	2
1429	151011	151011 - COLONIA|YAUYOS|DEPARTAMENTO LIMA	1,288	323.96	-13.000000	-76.000000	2
1430	151012	151012 - HONGOS|YAUYOS|DEPARTAMENTO LIMA	388	103.80	-13.000000	-76.000000	2
1431	151013	151013 - HUAMPARA|YAUYOS|DEPARTAMENTO LIMA	175	54.03	-12.000000	-76.000000	2
1432	151014	151014 - HUANCAYA|YAUYOS|DEPARTAMENTO LIMA	1,424	283.60	-12.000000	-76.000000	2
1433	151015	151015 - HUANGASCAR|YAUYOS|DEPARTAMENTO LIMA	549	50.46	-13.000000	-76.000000	2
1434	151016	151016 - HUANTAN|YAUYOS|DEPARTAMENTO LIMA	943	516.35	-12.000000	-76.000000	2
1435	151017	151017 - HUAÑEC|YAUYOS|DEPARTAMENTO LIMA	485	37.54	-12.000000	-76.000000	2
1436	151018	151018 - LARAOS|YAUYOS|DEPARTAMENTO LIMA	725	402.85	-12.000000	-76.000000	2
1437	151019	151019 - LINCHA|YAUYOS|DEPARTAMENTO LIMA	966	221.22	-13.000000	-76.000000	2
1438	151020	151020 - MADEAN|YAUYOS|DEPARTAMENTO LIMA	804	220.72	-13.000000	-76.000000	2
1439	151021	151021 - MIRAFLORES|YAUYOS|DEPARTAMENTO LIMA	447	226.24	-12.000000	-76.000000	2
1440	151022	151022 - OMAS|YAUYOS|DEPARTAMENTO LIMA	562	295.35	-13.000000	-76.000000	2
1441	151023	151023 - PUTINZA|YAUYOS|DEPARTAMENTO LIMA	489	66.44	-13.000000	-76.000000	2
1442	151024	151024 - QUINCHES|YAUYOS|DEPARTAMENTO LIMA	961	113.33	-12.000000	-76.000000	2
1443	151025	151025 - QUINOCAY|YAUYOS|DEPARTAMENTO LIMA	530	153.13	-12.000000	-76.000000	2
1444	151026	151026 - SAN JOAQUIN|YAUYOS|DEPARTAMENTO LIMA	452	41.24	-12.000000	-76.000000	2
1445	151027	151027 - SAN PEDRO DE PILAS|YAUYOS|DEPARTAMENTO LIMA	364	97.39	-12.000000	-76.000000	2
1446	151028	151028 - TANTA|YAUYOS|DEPARTAMENTO LIMA	504	347.15	-12.000000	-76.000000	2
1447	151029	151029 - TAURIPAMPA|YAUYOS|DEPARTAMENTO LIMA	405	530.86	-13.000000	-76.000000	2
1448	151030	151030 - TOMAS|YAUYOS|DEPARTAMENTO LIMA	1,151	297.93	-12.000000	-76.000000	2
1449	151031	151031 - TUPE|YAUYOS|DEPARTAMENTO LIMA	648	321.15	-13.000000	-76.000000	2
1450	151032	151032 - VIÑAC|YAUYOS|DEPARTAMENTO LIMA	1,906	165.23	-13.000000	-76.000000	2
1451	151033	151033 - VITIS|YAUYOS|DEPARTAMENTO LIMA	665	101.79	-12.000000	-76.000000	2
1452	160101	160101 - IQUITOS|MAYNAS|DEPARTAMENTO LORETO	151,072	358.15	-4.000000	-73.000000	2
1453	160102	160102 - ALTO NANAY|MAYNAS|DEPARTAMENTO LORETO	3,064	14,290.81	-4.000000	-74.000000	2
1454	160103	160103 - FERNANDO LORES|MAYNAS|DEPARTAMENTO LORETO	20,646	4,476.19	-4.000000	-73.000000	2
1455	160104	160104 - INDIANA|MAYNAS|DEPARTAMENTO LORETO	11,273	3,297.76	-3.000000	-73.000000	2
1456	160105	160105 - LAS AMAZONAS|MAYNAS|DEPARTAMENTO LORETO	9,926	6,593.64	-3.000000	-73.000000	2
1457	160106	160106 - MAZAN|MAYNAS|DEPARTAMENTO LORETO	14,057	9,884.28	-3.000000	-73.000000	2
1458	160107	160107 - NAPO|MAYNAS|DEPARTAMENTO LORETO	16,695	24,049.95	-2.000000	-74.000000	2
1459	160108	160108 - PUNCHANA|MAYNAS|DEPARTAMENTO LORETO	94,201	1,573.39	-4.000000	-73.000000	2
1460	160110	160110 - TORRES CAUSANA|MAYNAS|DEPARTAMENTO LORETO	5,238	6,795.14	-1.000000	-75.000000	2
1461	160112	160112 - BELEN|MAYNAS|DEPARTAMENTO LORETO	77,641	632.80	-4.000000	-73.000000	2
1462	160113	160113 - SAN JUAN BAUTISTA|MAYNAS|DEPARTAMENTO LORETO	161,819	3,117.05	-4.000000	-73.000000	2
1463	160201	160201 - YURIMAGUAS|ALTO AMAZONAS|DEPARTAMENTO LORETO	74,047	2,187.67	-6.000000	-76.000000	2
1464	160202	160202 - BALSAPUERTO|ALTO AMAZONAS|DEPARTAMENTO LORETO	18,042	2,954.17	-6.000000	-77.000000	2
1465	160205	160205 - JEBEROS|ALTO AMAZONAS|DEPARTAMENTO LORETO	5,453	4,253.68	-5.000000	-76.000000	2
1466	160206	160206 - LAGUNAS|ALTO AMAZONAS|DEPARTAMENTO LORETO	14,584	5,929.16	-5.000000	-76.000000	2
1467	160210	160210 - SANTA CRUZ|ALTO AMAZONAS|DEPARTAMENTO LORETO	4,535	2,222.31	-6.000000	-76.000000	2
1468	160211	160211 - TENIENTE CESAR LOPEZ ROJAS|ALTO AMAZONAS|DEPARTAMENTO LORETO	6,743	1,292.03	-6.000000	-76.000000	2
1469	160301	160301 - NAUTA|LORETO|DEPARTAMENTO LORETO	30,631	6,672.35	-4.000000	-74.000000	2
1470	160302	160302 - PARINARI|LORETO|DEPARTAMENTO LORETO	7,331	12,934.74	-5.000000	-74.000000	2
1471	160303	160303 - TIGRE|LORETO|DEPARTAMENTO LORETO	8,664	19,785.70	-4.000000	-75.000000	2
1472	160304	160304 - TROMPETEROS|LORETO|DEPARTAMENTO LORETO	11,196	12,246.01	-4.000000	-75.000000	2
1473	160305	160305 - URARINAS|LORETO|DEPARTAMENTO LORETO	15,270	15,434.46	-5.000000	-75.000000	2
1474	160401	160401 - RAMON CASTILLA|MARISCAL RAMON CASTILLA|DEPARTAMENTO LORETO	25,020	7,163.07	-4.000000	-71.000000	2
1475	160402	160402 - PEBAS|MARISCAL RAMON CASTILLA|DEPARTAMENTO LORETO	17,653	11,048.35	-3.000000	-72.000000	2
1476	160403	160403 - YAVARI|MARISCAL RAMON CASTILLA|DEPARTAMENTO LORETO	16,315	13,807.54	-4.000000	-70.000000	2
1477	160404	160404 - SAN PABLO|MARISCAL RAMON CASTILLA|DEPARTAMENTO LORETO	16,675	5,045.58	-4.000000	-71.000000	2
1478	160501	160501 - REQUENA|REQUENA|DEPARTAMENTO LORETO	31,004	3,038.56	-5.000000	-74.000000	2
1479	160502	160502 - ALTO TAPICHE|REQUENA|DEPARTAMENTO LORETO	2,139	9,013.80	-6.000000	-74.000000	2
1480	160503	160503 - CAPELO|REQUENA|DEPARTAMENTO LORETO	4,564	842.37	-5.000000	-74.000000	2
1481	160504	160504 - EMILIO SAN MARTIN|REQUENA|DEPARTAMENTO LORETO	7,637	4,572.56	-6.000000	-74.000000	2
1482	160505	160505 - MAQUIA|REQUENA|DEPARTAMENTO LORETO	8,508	4,792.06	-6.000000	-75.000000	2
1483	160506	160506 - PUINAHUA|REQUENA|DEPARTAMENTO LORETO	6,170	6,149.49	-5.000000	-74.000000	2
1484	160507	160507 - SAQUENA|REQUENA|DEPARTAMENTO LORETO	5,025	2,081.42	-5.000000	-74.000000	2
1485	160508	160508 - SOPLIN|REQUENA|DEPARTAMENTO LORETO	707	4,711.38	-6.000000	-74.000000	2
1486	160509	160509 - TAPICHE|REQUENA|DEPARTAMENTO LORETO	1,245	2,014.23	-6.000000	-74.000000	2
1487	160510	160510 - JENARO HERRERA|REQUENA|DEPARTAMENTO LORETO	5,754	1,517.43	-5.000000	-74.000000	2
1488	160511	160511 - YAQUERANA|REQUENA|DEPARTAMENTO LORETO	3,090	10,947.16	-5.000000	-73.000000	2
1489	160601	160601 - CONTAMANA|UCAYALI|DEPARTAMENTO LORETO	28,098	10,675.13	-7.000000	-75.000000	2
1490	160602	160602 - INAHUAYA|UCAYALI|DEPARTAMENTO LORETO	2,751	646.04	-7.000000	-75.000000	2
1491	160603	160603 - PADRE MARQUEZ|UCAYALI|DEPARTAMENTO LORETO	7,901	2,475.66	-8.000000	-75.000000	2
1492	160604	160604 - PAMPA HERMOSA|UCAYALI|DEPARTAMENTO LORETO	11,081	7,346.98	-7.000000	-75.000000	2
1493	160605	160605 - SARAYACU|UCAYALI|DEPARTAMENTO LORETO	16,913	6,303.17	-6.000000	-75.000000	2
1494	160606	160606 - VARGAS GUERRA|UCAYALI|DEPARTAMENTO LORETO	9,125	1,846.49	-7.000000	-75.000000	2
1495	160701	160701 - BARRANCA|DATEM DEL MARAÑON|DEPARTAMENTO LORETO	14,043	7,235.53	-5.000000	-77.000000	2
1496	160702	160702 - CAHUAPANAS|DATEM DEL MARAÑON|DEPARTAMENTO LORETO	8,639	4,685.11	-5.000000	-77.000000	2
1497	160703	160703 - MANSERICHE|DATEM DEL MARAÑON|DEPARTAMENTO LORETO	10,707	3,493.77	-5.000000	-77.000000	2
1498	160704	160704 - MORONA|DATEM DEL MARAÑON|DEPARTAMENTO LORETO	13,609	10,776.95	-4.000000	-77.000000	2
1499	160705	160705 - PASTAZA|DATEM DEL MARAÑON|DEPARTAMENTO LORETO	6,496	8,908.91	-5.000000	-77.000000	2
1500	160706	160706 - ANDOAS|DATEM DEL MARAÑON|DEPARTAMENTO LORETO	12,869	11,540.66	-3.000000	-76.000000	2
1501	160801	160801 - PUTUMAYO|MAYNAS|DEPARTAMENTO LORETO	4,341	10,886.41	-2.000000	-73.000000	2
1502	160802	160802 - ROSA PANDURO|MAYNAS|DEPARTAMENTO LORETO	745	7,038.69	-2.000000	-73.000000	2
1503	160803	160803 - TENIENTE MANUEL CLAVERO|MAYNAS|DEPARTAMENTO LORETO	5926	9,488.52	0.000000	-75.000000	2
1504	160804	160804 - YAGUAS|MAYNAS|DEPARTAMENTO LORETO	1,252	17,725.02	-2.000000	-71.000000	2
1505	170101	170101 - TAMBOPATA|TAMBOPATA|DEPARTAMENTO MADRE DE DIOS	84,207	22,218.56	-13.000000	-69.000000	2
1506	170102	170102 - INAMBARI|TAMBOPATA|DEPARTAMENTO MADRE DE DIOS	10,818	4,256.82	-13.000000	-70.000000	2
1507	170103	170103 - LAS PIEDRAS|TAMBOPATA|DEPARTAMENTO MADRE DE DIOS	6,101	7,032.21	-12.000000	-69.000000	2
1508	170104	170104 - LABERINTO|TAMBOPATA|DEPARTAMENTO MADRE DE DIOS	5,329	2,760.90	-13.000000	-70.000000	2
1509	170201	170201 - MANU|MANU|DEPARTAMENTO MADRE DE DIOS	3,321	8,166.65	-13.000000	-71.000000	2
1510	170202	170202 - FITZCARRALD|MANU|DEPARTAMENTO MADRE DE DIOS	1,641	10,955.29	-12.000000	-71.000000	2
1511	170203	170203 - MADRE DE DIOS|MANU|DEPARTAMENTO MADRE DE DIOS	13,835	7,234.81	-13.000000	-70.000000	2
1512	170204	170204 - HUEPETUHE|MANU|DEPARTAMENTO MADRE DE DIOS	6,802	1,478.42	-13.000000	-71.000000	2
1513	170301	170301 - IÑAPARI|TAHUAMANU|DEPARTAMENTO MADRE DE DIOS	1,659	14,853.66	-11.000000	-70.000000	2
1514	170302	170302 - IBERIA|TAHUAMANU|DEPARTAMENTO MADRE DE DIOS	9,486	2,549.32	-11.000000	-69.000000	2
1515	170303	170303 - TAHUAMANU|TAHUAMANU|DEPARTAMENTO MADRE DE DIOS	3,658	3,793.90	-11.000000	-69.000000	2
1516	180101	180101 - MOQUEGUA|MARISCAL NIETO|DEPARTAMENTO MOQUEGUA	59,387	3,949.04	-17.000000	-71.000000	2
1517	180102	180102 - CARUMAS|MARISCAL NIETO|DEPARTAMENTO MOQUEGUA	5,805	2,256.31	-17.000000	-71.000000	2
1518	180103	180103 - CUCHUMBAYA|MARISCAL NIETO|DEPARTAMENTO MOQUEGUA	2,228	67.58	-17.000000	-71.000000	2
1519	180104	180104 - SAMEGUA|MARISCAL NIETO|DEPARTAMENTO MOQUEGUA	6,581	62.55	-17.000000	-71.000000	2
1520	180105	180105 - SAN CRISTOBAL|MARISCAL NIETO|DEPARTAMENTO MOQUEGUA	4,190	542.73	-17.000000	-71.000000	2
1521	180106	180106 - TORATA|MARISCAL NIETO|DEPARTAMENTO MOQUEGUA	5,784	1,793.37	-17.000000	-71.000000	2
1522	180201	180201 - OMATE|GENERAL SANCHEZ CERR|DEPARTAMENTO MOQUEGUA	4,661	250.64	-17.000000	-71.000000	2
1523	180202	180202 - CHOJATA|GENERAL SANCHEZ CERR|DEPARTAMENTO MOQUEGUA	2,685	847.94	-16.000000	-71.000000	2
1524	180203	180203 - COALAQUE|GENERAL SANCHEZ CERR|DEPARTAMENTO MOQUEGUA	1104	247.58	-17.000000	-71.000000	2
1525	180204	180204 - ICHUÑA|GENERAL SANCHEZ CERR|DEPARTAMENTO MOQUEGUA	5,048	1,017.74	-16.000000	-71.000000	2
1526	180205	180205 - LA CAPILLA|GENERAL SANCHEZ CERR|DEPARTAMENTO MOQUEGUA	2,326	776.04	-17.000000	-71.000000	2
1527	180206	180206 - LLOQUE|GENERAL SANCHEZ CERR|DEPARTAMENTO MOQUEGUA	2,087	254.45	-16.000000	-71.000000	2
1528	180207	180207 - MATALAQUE|GENERAL SANCHEZ CERR|DEPARTAMENTO MOQUEGUA	1,236	557.23	-16.000000	-71.000000	2
1529	180208	180208 - PUQUINA|GENERAL SANCHEZ CERR|DEPARTAMENTO MOQUEGUA	2,469	550.99	-17.000000	-71.000000	2
1530	180209	180209 - QUINISTAQUILLAS|GENERAL SANCHEZ CERR|DEPARTAMENTO MOQUEGUA	1,487	193.79	-17.000000	-71.000000	2
1531	180210	180210 - UBINAS|GENERAL SANCHEZ CERR|DEPARTAMENTO MOQUEGUA	3,714	874.57	-16.000000	-71.000000	2
1532	180211	180211 - YUNGA|GENERAL SANCHEZ CERR|DEPARTAMENTO MOQUEGUA	2,514	110.74	-16.000000	-71.000000	2
1533	180301	180301 - ILO|ILO|DEPARTAMENTO MOQUEGUA	69,079	295.51	-18.000000	-71.000000	2
1534	180302	180302 - EL ALGARROBAL|ILO|DEPARTAMENTO MOQUEGUA	332	747.00	-18.000000	-71.000000	2
1535	180303	180303 - PACOCHA|ILO|DEPARTAMENTO MOQUEGUA	3,319	338.08	-18.000000	-71.000000	2
1536	190101	190101 - CHAUPIMARCA|PASCO|DEPARTAMENTO PASCO	25,724	6.66	-11.000000	-76.000000	2
1537	190102	190102 - HUACHON|PASCO|DEPARTAMENTO PASCO	4,762	846.30	-11.000000	-76.000000	2
1538	190103	190103 - HUARIACA|PASCO|DEPARTAMENTO PASCO	8,278	133.07	-10.000000	-76.000000	2
1539	190104	190104 - HUAYLLAY|PASCO|DEPARTAMENTO PASCO	11,564	1,026.87	-11.000000	-76.000000	2
1540	190105	190105 - NINACACA|PASCO|DEPARTAMENTO PASCO	3,297	508.92	-11.000000	-76.000000	2
1541	190106	190106 - PALLANCHACRA|PASCO|DEPARTAMENTO PASCO	5,040	73.69	-10.000000	-76.000000	2
1542	190107	190107 - PAUCARTAMBO|PASCO|DEPARTAMENTO PASCO	25,070	782.19	-11.000000	-76.000000	2
1543	190108	190108 - SAN FRANCISCO DE ASIS DE YARUSYACAN|PASCO|DEPARTAMENTO PASCO	9,518	117.70	-10.000000	-76.000000	2
1544	190109	190109 - SIMON BOLIVAR|PASCO|DEPARTAMENTO PASCO	11,404	697.15	-11.000000	-76.000000	2
1545	190110	190110 - TICLACAYAN|PASCO|DEPARTAMENTO PASCO	14,863	748.43	-11.000000	-76.000000	2
1546	190111	190111 - TINYAHUARCO|PASCO|DEPARTAMENTO PASCO	6,346	94.49	-11.000000	-76.000000	2
1547	190112	190112 - VICCO|PASCO|DEPARTAMENTO PASCO	2,173	173.30	-11.000000	-76.000000	2
1548	190113	190113 - YANACANCHA|PASCO|DEPARTAMENTO PASCO	30,792	165.11	-11.000000	-76.000000	2
1549	190201	190201 - YANAHUANCA|DANIEL ALCIDES CARRI|DEPARTAMENTO PASCO	12,963	921.06	-10.000000	-77.000000	2
1550	190202	190202 - CHACAYAN|DANIEL ALCIDES CARRI|DEPARTAMENTO PASCO	4,439	198.58	-10.000000	-76.000000	2
1551	190203	190203 - GOYLLARISQUIZGA|DANIEL ALCIDES CARRI|DEPARTAMENTO PASCO	4,234	23.17	-10.000000	-76.000000	2
1552	190204	190204 - PAUCAR|DANIEL ALCIDES CARRI|DEPARTAMENTO PASCO	1,721	134.18	-10.000000	-76.000000	2
1553	190205	190205 - SAN PEDRO DE PILLAO|DANIEL ALCIDES CARRI|DEPARTAMENTO PASCO	1,883	92.17	-10.000000	-76.000000	2
1554	190206	190206 - SANTA ANA DE TUSI|DANIEL ALCIDES CARRI|DEPARTAMENTO PASCO	23,892	353.11	-10.000000	-76.000000	2
1555	190207	190207 - TAPUC|DANIEL ALCIDES CARRI|DEPARTAMENTO PASCO	4,533	60.19	-10.000000	-76.000000	2
1556	190208	190208 - VILCABAMBA|DANIEL ALCIDES CARRI|DEPARTAMENTO PASCO	1,563	102.35	-10.000000	-76.000000	2
1557	190301	190301 - OXAPAMPA|OXAPAMPA|DEPARTAMENTO PASCO	14,438	419.85	-11.000000	-75.000000	2
1558	190302	190302 - CHONTABAMBA|OXAPAMPA|DEPARTAMENTO PASCO	3,598	457.09	-11.000000	-75.000000	2
1559	190303	190303 - HUANCABAMBA|OXAPAMPA|DEPARTAMENTO PASCO	6,628	1,182.15	-10.000000	-76.000000	2
1560	190304	190304 - PALCAZU|OXAPAMPA|DEPARTAMENTO PASCO	11,282	2,912.16	-10.000000	-75.000000	2
1561	190305	190305 - POZUZO|OXAPAMPA|DEPARTAMENTO PASCO	9,818	750.87	-10.000000	-76.000000	2
1562	190306	190306 - PUERTO BERMUDEZ|OXAPAMPA|DEPARTAMENTO PASCO	18,016	8,014.31	-10.000000	-75.000000	2
1563	190307	190307 - VILLA RICA|OXAPAMPA|DEPARTAMENTO PASCO	20,633	859.23	-11.000000	-75.000000	2
1564	190308	190308 - CONSTITUCIÓN|OXAPAMPA|DEPARTAMENTO PASCO	12,105	3,171.49	-10.000000	-75.000000	2
1565	200101	200101 - PIURA|PIURA|DEPARTAMENTO PIURA	158,034	196.15	-5.000000	-81.000000	2
1566	200104	200104 - CASTILLA|PIURA|DEPARTAMENTO PIURA	147,546	656.69	-5.000000	-81.000000	2
1567	200105	200105 - CATACAOS|PIURA|DEPARTAMENTO PIURA	74,562	2,286.97	-5.000000	-81.000000	2
1568	200107	200107 - CURA MORI|PIURA|DEPARTAMENTO PIURA	19,168	217.41	-5.000000	-81.000000	2
1569	200108	200108 - EL TALLAN|PIURA|DEPARTAMENTO PIURA	5,215	100.98	-5.000000	-81.000000	2
1570	200109	200109 - LA ARENA|PIURA|DEPARTAMENTO PIURA	38,483	171.24	-5.000000	-81.000000	2
1571	200110	200110 - LA UNION|PIURA|DEPARTAMENTO PIURA	41,736	320.90	-5.000000	-81.000000	2
1572	200111	200111 - LAS LOMAS|PIURA|DEPARTAMENTO PIURA	27,290	557.69	-5.000000	-80.000000	2
1573	200114	200114 - TAMBO GRANDE|PIURA|DEPARTAMENTO PIURA	123,352	1,496.75	-5.000000	-80.000000	2
1574	200115	200115 - 26 DE OCTUBRE|PIURA|DEPARTAMENTO PIURA	151,916	72.01	-5.000000	-81.000000	2
1575	200201	200201 - AYABACA|AYABACA|DEPARTAMENTO PIURA	38,963	1,549.99	-5.000000	-80.000000	2
1576	200202	200202 - FRIAS|AYABACA|DEPARTAMENTO PIURA	24,461	565.31	-5.000000	-80.000000	2
1577	200203	200203 - JILILI|AYABACA|DEPARTAMENTO PIURA	2,768	104.73	-5.000000	-80.000000	2
1578	200204	200204 - LAGUNAS|AYABACA|DEPARTAMENTO PIURA	7,425	190.82	-5.000000	-80.000000	2
1579	200205	200205 - MONTERO|AYABACA|DEPARTAMENTO PIURA	6,619	130.57	-5.000000	-80.000000	2
1580	200206	200206 - PACAIPAMPA|AYABACA|DEPARTAMENTO PIURA	25,060	981.50	-5.000000	-80.000000	2
1581	200207	200207 - PAIMAS|AYABACA|DEPARTAMENTO PIURA	10,504	319.67	-5.000000	-80.000000	2
1582	200208	200208 - SAPILLICA|AYABACA|DEPARTAMENTO PIURA	12,442	267.09	-5.000000	-80.000000	2
1583	200209	200209 - SICCHEZ|AYABACA|DEPARTAMENTO PIURA	1,829	33.10	-5.000000	-80.000000	2
1584	200210	200210 - SUYO|AYABACA|DEPARTAMENTO PIURA	12,471	1,078.61	-5.000000	-80.000000	2
1585	200301	200301 - HUANCABAMBA|HUANCABAMBA|DEPARTAMENTO PIURA	30,956	446.75	-5.000000	-79.000000	2
1586	200302	200302 - CANCHAQUE|HUANCABAMBA|DEPARTAMENTO PIURA	8,173	306.41	-5.000000	-80.000000	2
1587	200303	200303 - EL CARMEN DE LA FRONTERA|HUANCABAMBA|DEPARTAMENTO PIURA	14,195	702.81	-5.000000	-79.000000	2
1588	200304	200304 - HUARMACA|HUANCABAMBA|DEPARTAMENTO PIURA	41,688	1,908.22	-6.000000	-80.000000	2
1589	200305	200305 - LALAQUIZ|HUANCABAMBA|DEPARTAMENTO PIURA	4,666	138.95	-5.000000	-80.000000	2
1590	200306	200306 - SAN MIGUEL DE EL FAIQUE|HUANCABAMBA|DEPARTAMENTO PIURA	9,067	201.60	-5.000000	-80.000000	2
1591	200307	200307 - SONDOR|HUANCABAMBA|DEPARTAMENTO PIURA	8,679	336.53	-5.000000	-79.000000	2
1592	200308	200308 - SONDORILLO|HUANCABAMBA|DEPARTAMENTO PIURA	10,910	226.09	-5.000000	-79.000000	2
1593	200401	200401 - CHULUCANAS|MORROPON|DEPARTAMENTO PIURA	76,815	842.26	-5.000000	-80.000000	2
1594	200402	200402 - BUENOS AIRES|MORROPON|DEPARTAMENTO PIURA	8,147	245.12	-5.000000	-80.000000	2
1595	200403	200403 - CHALACO|MORROPON|DEPARTAMENTO PIURA	9,190	151.96	-5.000000	-80.000000	2
1596	200404	200404 - LA MATANZA|MORROPON|DEPARTAMENTO PIURA	12,912	1,043.61	-5.000000	-80.000000	2
1597	200405	200405 - MORROPON|MORROPON|DEPARTAMENTO PIURA	14,240	169.96	-5.000000	-80.000000	2
1598	200406	200406 - SALITRAL|MORROPON|DEPARTAMENTO PIURA	8,470	614.03	-5.000000	-80.000000	2
1599	200407	200407 - SAN JUAN DE BIGOTE|MORROPON|DEPARTAMENTO PIURA	6,747	245.21	-5.000000	-80.000000	2
1600	200408	200408 - SANTA CATALINA DE MOSSA|MORROPON|DEPARTAMENTO PIURA	4,187	76.76	-5.000000	-80.000000	2
1601	200409	200409 - SANTO DOMINGO|MORROPON|DEPARTAMENTO PIURA	7,335	187.32	-5.000000	-80.000000	2
1602	200410	200410 - YAMANGO|MORROPON|DEPARTAMENTO PIURA	9,715	216.91	-5.000000	-80.000000	2
1603	200501	200501 - PAITA|PAITA|DEPARTAMENTO PIURA	96,707	706.31	-5.000000	-81.000000	2
1604	200502	200502 - AMOTAPE|PAITA|DEPARTAMENTO PIURA	2,336	90.82	-5.000000	-81.000000	2
1605	200503	200503 - ARENAL|PAITA|DEPARTAMENTO PIURA	1,049	8.19	-5.000000	-81.000000	2
1606	200504	200504 - COLAN|PAITA|DEPARTAMENTO PIURA	12,625	124.93	-5.000000	-81.000000	2
1607	200505	200505 - LA HUACA|PAITA|DEPARTAMENTO PIURA	11,921	599.51	-5.000000	-81.000000	2
1608	200506	200506 - TAMARINDO|PAITA|DEPARTAMENTO PIURA	4,632	63.67	-5.000000	-81.000000	2
1609	200507	200507 - VICHAYAL|PAITA|DEPARTAMENTO PIURA	4,901	134.36	-5.000000	-81.000000	2
1610	200601	200601 - SULLANA|SULLANA|DEPARTAMENTO PIURA	180,896	529.73	-5.000000	-81.000000	2
1611	200602	200602 - BELLAVISTA|SULLANA|DEPARTAMENTO PIURA	38,621	3.09	-5.000000	-81.000000	2
1612	200603	200603 - IGNACIO ESCUDERO|SULLANA|DEPARTAMENTO PIURA	20,502	306.53	-5.000000	-81.000000	2
1613	200604	200604 - LANCONES|SULLANA|DEPARTAMENTO PIURA	13,525	2,152.99	-5.000000	-80.000000	2
1614	200605	200605 - MARCAVELICA|SULLANA|DEPARTAMENTO PIURA	29,411	1,687.98	-5.000000	-81.000000	2
1615	200606	200606 - MIGUEL CHECA|SULLANA|DEPARTAMENTO PIURA	8,861	480.26	-5.000000	-81.000000	2
1616	200607	200607 - QUERECOTILLO|SULLANA|DEPARTAMENTO PIURA	25,675	270.08	-5.000000	-81.000000	2
1617	200608	200608 - SALITRAL|SULLANA|DEPARTAMENTO PIURA	6834	28.27	-5.000000	-81.000000	2
1618	200701	200701 - PARIÑAS|TALARA|DEPARTAMENTO PIURA	91,278	1,116.99	-5.000000	-81.000000	2
1619	200702	200702 - EL ALTO|TALARA|DEPARTAMENTO PIURA	7,114	491.33	-4.000000	-81.000000	2
1620	200703	200703 - LA BREA|TALARA|DEPARTAMENTO PIURA	11,926	692.96	-5.000000	-81.000000	2
1621	200704	200704 - LOBITOS|TALARA|DEPARTAMENTO PIURA	1,685	233.01	-4.000000	-81.000000	2
1622	200705	200705 - LOS ORGANOS|TALARA|DEPARTAMENTO PIURA	9,510	165.01	-4.000000	-81.000000	2
1623	200706	200706 - MANCORA|TALARA|DEPARTAMENTO PIURA	13,045	100.19	-4.000000	-81.000000	2
1624	200801	200801 - SECHURA|SECHURA|DEPARTAMENTO PIURA	44,407	5,710.85	-6.000000	-81.000000	2
1625	200802	200802 - BELLAVISTA DE LA UNION|SECHURA|DEPARTAMENTO PIURA	4,498	13.88	-5.000000	-81.000000	2
1626	200803	200803 - BERNAL|SECHURA|DEPARTAMENTO PIURA	7,477	71.74	-5.000000	-81.000000	2
1627	200804	200804 - CRISTO NOS VALGA|SECHURA|DEPARTAMENTO PIURA	4,067	234.37	-5.000000	-81.000000	2
1628	200805	200805 - VICE|SECHURA|DEPARTAMENTO PIURA	14,475	261.01	-5.000000	-81.000000	2
1629	200806	200806 - RINCONADA LLICUAR|SECHURA|DEPARTAMENTO PIURA	3,298	19.44	-5.000000	-81.000000	2
1630	210101	210101 - PUNO|PUNO|DEPARTAMENTO PUNO	146095	460.63	-16.000000	-70.000000	2
1631	210102	210102 - ACORA|PUNO|DEPARTAMENTO PUNO	28,363	1,941.09	-16.000000	-70.000000	2
1632	210103	210103 - AMANTANI|PUNO|DEPARTAMENTO PUNO	4,538	15.00	-16.000000	-70.000000	2
1633	210104	210104 - ATUNCOLLA|PUNO|DEPARTAMENTO PUNO	5778	124.74	-16.000000	-70.000000	2
1634	210105	210105 - CAPACHICA|PUNO|DEPARTAMENTO PUNO	11,436	117.06	-16.000000	-70.000000	2
1635	210106	210106 - CHUCUITO|PUNO|DEPARTAMENTO PUNO	6,807	121.18	-16.000000	-70.000000	2
1636	210107	210107 - COATA|PUNO|DEPARTAMENTO PUNO	8265	104.00	-16.000000	-70.000000	2
1637	210108	210108 - HUATA|PUNO|DEPARTAMENTO PUNO	10988	130.37	-16.000000	-70.000000	2
1638	210109	210109 - MAÑAZO|PUNO|DEPARTAMENTO PUNO	5,400	410.67	-16.000000	-70.000000	2
1639	210110	210110 - PAUCARCOLLA|PUNO|DEPARTAMENTO PUNO	5254	170.04	-16.000000	-70.000000	2
1640	210111	210111 - PICHACANI|PUNO|DEPARTAMENTO PUNO	5298	1,633.48	-16.000000	-70.000000	2
1641	210112	210112 - PLATERIA|PUNO|DEPARTAMENTO PUNO	7,674	238.59	-16.000000	-70.000000	2
1642	210113	210113 - SAN ANTONIO|PUNO|DEPARTAMENTO PUNO	4025	376.75	-16.000000	-70.000000	2
1643	210114	210114 - TIQUILLACA|PUNO|DEPARTAMENTO PUNO	1,725	455.71	-16.000000	-70.000000	2
1644	210115	210115 - VILQUE|PUNO|DEPARTAMENTO PUNO	3163	193.29	-16.000000	-70.000000	2
1645	210201	210201 - AZANGARO|AZANGARO|DEPARTAMENTO PUNO	28,809	706.13	-15.000000	-70.000000	2
1646	210202	210202 - ACHAYA|AZANGARO|DEPARTAMENTO PUNO	4,619	132.23	-15.000000	-70.000000	2
1647	210203	210203 - ARAPA|AZANGARO|DEPARTAMENTO PUNO	7,707	329.85	-15.000000	-70.000000	2
1648	210204	210204 - ASILLO|AZANGARO|DEPARTAMENTO PUNO	17,767	392.38	-15.000000	-70.000000	2
1649	210205	210205 - CAMINACA|AZANGARO|DEPARTAMENTO PUNO	3,543	146.88	-15.000000	-70.000000	2
1650	210206	210206 - CHUPA|AZANGARO|DEPARTAMENTO PUNO	13,200	143.21	-15.000000	-70.000000	2
1651	210207	210207 - JOSE DOMINGO CHOQUEHUANCA|AZANGARO|DEPARTAMENTO PUNO	5,595	69.73	-15.000000	-70.000000	2
1652	210208	210208 - MUÑANI|AZANGARO|DEPARTAMENTO PUNO	8,367	764.49	-15.000000	-70.000000	2
1653	210209	210209 - POTONI|AZANGARO|DEPARTAMENTO PUNO	6,586	602.95	-14.000000	-70.000000	2
1654	210210	210210 - SAMAN|AZANGARO|DEPARTAMENTO PUNO	14,541	188.59	-15.000000	-70.000000	2
1655	210211	210211 - SAN ANTON|AZANGARO|DEPARTAMENTO PUNO	10,186	514.84	-15.000000	-70.000000	2
1656	210212	210212 - SAN JOSE|AZANGARO|DEPARTAMENTO PUNO	5838	372.73	-15.000000	-70.000000	2
1657	210213	210213 - SAN JUAN DE SALINAS|AZANGARO|DEPARTAMENTO PUNO	4,430	106.00	-15.000000	-70.000000	2
1658	210214	210214 - SANTIAGO DE PUPUJA|AZANGARO|DEPARTAMENTO PUNO	5,400	301.27	-15.000000	-70.000000	2
1659	210215	210215 - TIRAPATA|AZANGARO|DEPARTAMENTO PUNO	3,141	198.73	-15.000000	-70.000000	2
1660	210301	210301 - MACUSANI|CARABAYA|DEPARTAMENTO PUNO	13,291	1,029.56	-14.000000	-70.000000	2
1661	210302	210302 - AJOYANI|CARABAYA|DEPARTAMENTO PUNO	2,140	413.11	-14.000000	-70.000000	2
1662	210303	210303 - AYAPATA|CARABAYA|DEPARTAMENTO PUNO	12,540	1,091.61	-14.000000	-70.000000	2
1663	210304	210304 - COASA|CARABAYA|DEPARTAMENTO PUNO	16,619	3,572.92	-14.000000	-70.000000	2
1664	210305	210305 - CORANI|CARABAYA|DEPARTAMENTO PUNO	4,035	852.99	-14.000000	-71.000000	2
1665	210306	210306 - CRUCERO|CARABAYA|DEPARTAMENTO PUNO	9,497	836.37	-14.000000	-70.000000	2
1666	210307	210307 - ITUATA|CARABAYA|DEPARTAMENTO PUNO	6,501	1,200.79	-14.000000	-70.000000	2
1667	210308	210308 - OLLACHEA|CARABAYA|DEPARTAMENTO PUNO	5,765	595.79	-14.000000	-70.000000	2
1668	210309	210309 - SAN GABAN|CARABAYA|DEPARTAMENTO PUNO	4,199	2,029.22	-13.000000	-70.000000	2
1669	210310	210310 - USICAYOS|CARABAYA|DEPARTAMENTO PUNO	24,668	644.04	-14.000000	-70.000000	2
1670	210401	210401 - JULI|CHUCUITO|DEPARTAMENTO PUNO	21,619	720.38	-16.000000	-69.000000	2
1671	210402	210402 - DESAGUADERO|CHUCUITO|DEPARTAMENTO PUNO	32,339	178.21	-17.000000	-69.000000	2
1672	210403	210403 - HUACULLANI|CHUCUITO|DEPARTAMENTO PUNO	23,781	705.28	-17.000000	-69.000000	2
1673	210404	210404 - KELLUYO|CHUCUITO|DEPARTAMENTO PUNO	26,051	485.77	-17.000000	-69.000000	2
1674	210405	210405 - PISACOMA|CHUCUITO|DEPARTAMENTO PUNO	13,871	959.34	-17.000000	-69.000000	2
1675	210406	210406 - POMATA|CHUCUITO|DEPARTAMENTO PUNO	16,206	382.58	-16.000000	-69.000000	2
1676	210407	210407 - ZEPITA|CHUCUITO|DEPARTAMENTO PUNO	19,161	546.57	-16.000000	-69.000000	2
1677	210501	210501 - ILAVE|EL COLLAO|DEPARTAMENTO PUNO	59,120	874.57	-16.000000	-70.000000	2
1678	210502	210502 - CAPAZO|EL COLLAO|DEPARTAMENTO PUNO	2,351	1,039.25	-17.000000	-70.000000	2
1679	210503	210503 - PILCUYO|EL COLLAO|DEPARTAMENTO PUNO	13,172	157.00	-16.000000	-70.000000	2
1680	210504	210504 - SANTA ROSA|EL COLLAO|DEPARTAMENTO PUNO	7,989	2,524.02	-17.000000	-70.000000	2
1681	210505	210505 - CONDURIRI|EL COLLAO|DEPARTAMENTO PUNO	4,496	1,005.67	-17.000000	-70.000000	2
1682	210601	210601 - HUANCANE|HUANCANE|DEPARTAMENTO PUNO	18,727	381.62	-15.000000	-70.000000	2
1683	210602	210602 - COJATA|HUANCANE|DEPARTAMENTO PUNO	4,501	881.18	-15.000000	-69.000000	2
1684	210603	210603 - HUATASANI|HUANCANE|DEPARTAMENTO PUNO	5,634	106.73	-15.000000	-70.000000	2
1685	210604	210604 - INCHUPALLA|HUANCANE|DEPARTAMENTO PUNO	3,422	289.03	-15.000000	-70.000000	2
1686	210605	210605 - PUSI|HUANCANE|DEPARTAMENTO PUNO	6,515	148.42	-15.000000	-70.000000	2
1687	210606	210606 - ROSASPATA|HUANCANE|DEPARTAMENTO PUNO	5,326	301.47	-15.000000	-70.000000	2
1688	210607	210607 - TARACO|HUANCANE|DEPARTAMENTO PUNO	14,483	198.02	-15.000000	-70.000000	2
1689	210608	210608 - VILQUE CHICO|HUANCANE|DEPARTAMENTO PUNO	8,480	499.38	-15.000000	-70.000000	2
1690	210701	210701 - LAMPA|LAMPA|DEPARTAMENTO PUNO	10,351	675.82	-15.000000	-70.000000	2
1691	210702	210702 - CABANILLA|LAMPA|DEPARTAMENTO PUNO	5,383	443.04	-16.000000	-70.000000	2
1692	210703	210703 - CALAPUJA|LAMPA|DEPARTAMENTO PUNO	1,506	141.30	-15.000000	-70.000000	2
1693	210704	210704 - NICASIO|LAMPA|DEPARTAMENTO PUNO	2,710	134.35	-15.000000	-70.000000	2
1694	210705	210705 - OCUVIRI|LAMPA|DEPARTAMENTO PUNO	3,246	878.26	-15.000000	-71.000000	2
1695	210706	210706 - PALCA|LAMPA|DEPARTAMENTO PUNO	2,871	483.96	-15.000000	-71.000000	2
1696	210707	210707 - PARATIA|LAMPA|DEPARTAMENTO PUNO	9,675	745.08	-15.000000	-71.000000	2
1697	210708	210708 - PUCARA|LAMPA|DEPARTAMENTO PUNO	5,201	537.60	-15.000000	-70.000000	2
1698	210709	210709 - SANTA LUCIA|LAMPA|DEPARTAMENTO PUNO	7,620	1,595.67	-16.000000	-71.000000	2
1699	210710	210710 - VILAVILA|LAMPA|DEPARTAMENTO PUNO	4,449	156.65	-15.000000	-71.000000	2
1700	210801	210801 - AYAVIRI|MELGAR|DEPARTAMENTO PUNO	22,568	1,013.14	-15.000000	-71.000000	2
1701	210802	210802 - ANTAUTA|MELGAR|DEPARTAMENTO PUNO	4,447	636.17	-14.000000	-70.000000	2
1702	210803	210803 - CUPI|MELGAR|DEPARTAMENTO PUNO	3,519	214.25	-15.000000	-71.000000	2
1703	210804	210804 - LLALLI|MELGAR|DEPARTAMENTO PUNO	5,003	216.36	-15.000000	-71.000000	2
1704	210805	210805 - MACARI|MELGAR|DEPARTAMENTO PUNO	8,772	673.78	-15.000000	-71.000000	2
1705	210806	210806 - NUÑOA|MELGAR|DEPARTAMENTO PUNO	11,106	2,200.16	-14.000000	-71.000000	2
1706	210807	210807 - ORURILLO|MELGAR|DEPARTAMENTO PUNO	11,009	379.05	-15.000000	-71.000000	2
1707	210808	210808 - SANTA ROSA|MELGAR|DEPARTAMENTO PUNO	7,526	790.38	-15.000000	-71.000000	2
1708	210809	210809 - UMACHIRI|MELGAR|DEPARTAMENTO PUNO	4,504	323.56	-15.000000	-71.000000	2
1709	210901	210901 - MOHO|MOHO|DEPARTAMENTO PUNO	16,058	494.36	-15.000000	-70.000000	2
1710	210902	210902 - CONIMA|MOHO|DEPARTAMENTO PUNO	3,064	72.95	-15.000000	-69.000000	2
1711	210903	210903 - HUAYRAPATA|MOHO|DEPARTAMENTO PUNO	4282	388.35	-15.000000	-69.000000	2
1712	210904	210904 - TILALI|MOHO|DEPARTAMENTO PUNO	2769	48.15	-16.000000	-69.000000	2
1713	211001	211001 - PUTINA|SAN ANTONIO DE PUTIN|DEPARTAMENTO PUNO	27,607	1,021.92	-15.000000	-70.000000	2
1714	211002	211002 - ANANEA|SAN ANTONIO DE PUTIN|DEPARTAMENTO PUNO	33,728	939.56	-15.000000	-70.000000	2
1715	211003	211003 - PEDRO VILCA APAZA|SAN ANTONIO DE PUTIN|DEPARTAMENTO PUNO	3054	565.81	-15.000000	-70.000000	2
1716	211004	211004 - QUILCAPUNCU|SAN ANTONIO DE PUTIN|DEPARTAMENTO PUNO	5929	516.66	-15.000000	-70.000000	2
1717	211005	211005 - SINA|SAN ANTONIO DE PUTIN|DEPARTAMENTO PUNO	1761	163.43	-14.000000	-69.000000	2
1718	211101	211101 - JULIACA|SAN ROMAN|DEPARTAMENTO PUNO	235,221	533.50	-15.000000	-70.000000	2
1719	211102	211102 - CABANA|SAN ROMAN|DEPARTAMENTO PUNO	4224	191.23	-16.000000	-70.000000	2
1720	211103	211103 - CABANILLAS|SAN ROMAN|DEPARTAMENTO PUNO	5459	1,267.06	-16.000000	-70.000000	2
1721	211104	211104 - CARACOTO|SAN ROMAN|DEPARTAMENTO PUNO	5608	285.87	-16.000000	-70.000000	2
1722	211105	211105 - SAN MIGUEL|SAN ROMAN|DEPARTAMENTO PUNO	54,060	121.80	-15.000000	-70.000000	2
1723	211201	211201 - SANDIA|SANDIA|DEPARTAMENTO PUNO	12,478	580.13	-14.000000	-69.000000	2
1724	211202	211202 - CUYOCUYO|SANDIA|DEPARTAMENTO PUNO	4768	503.91	-14.000000	-70.000000	2
1725	211203	211203 - LIMBANI|SANDIA|DEPARTAMENTO PUNO	4,422	2,112.34	-14.000000	-70.000000	2
1726	211204	211204 - PATAMBUCO|SANDIA|DEPARTAMENTO PUNO	3967	462.72	-14.000000	-70.000000	2
1727	211205	211205 - PHARA|SANDIA|DEPARTAMENTO PUNO	4,905	400.90	-14.000000	-70.000000	2
1728	211206	211206 - QUIACA|SANDIA|DEPARTAMENTO PUNO	2413	447.90	-14.000000	-69.000000	2
1729	211207	211207 - SAN JUAN DEL ORO|SANDIA|DEPARTAMENTO PUNO	14201	197.14	-14.000000	-69.000000	2
1730	211208	211208 - YANAHUAYA|SANDIA|DEPARTAMENTO PUNO	2,244	670.61	-14.000000	-69.000000	2
1731	211209	211209 - ALTO INAMBARI|SANDIA|DEPARTAMENTO PUNO	9,765	1,124.88	-14.000000	-69.000000	2
1732	211210	211210 - SAN PEDRO DE PUTINA PUNCO|SANDIA|DEPARTAMENTO PUNO	14,560	5,361.88	-14.000000	-69.000000	2
1733	211301	211301 - YUNGUYO|YUNGUYO|DEPARTAMENTO PUNO	27385	170.59	-16.000000	-69.000000	2
1734	211302	211302 - ANAPIA|YUNGUYO|DEPARTAMENTO PUNO	3,376	9.54	-16.000000	-69.000000	2
1735	211303	211303 - COPANI|YUNGUYO|DEPARTAMENTO PUNO	5040	47.37	-16.000000	-69.000000	2
1736	211304	211304 - CUTURAPI|YUNGUYO|DEPARTAMENTO PUNO	1,245	21.74	-16.000000	-69.000000	2
1737	211305	211305 - OLLARAYA|YUNGUYO|DEPARTAMENTO PUNO	5,376	23.67	-16.000000	-69.000000	2
1738	211306	211306 - TINICACHI|YUNGUYO|DEPARTAMENTO PUNO	1,629	6.20	-16.000000	-69.000000	2
1739	211307	211307 - UNICACHI|YUNGUYO|DEPARTAMENTO PUNO	3889	11.10	-16.000000	-69.000000	2
1740	220101	220101 - MOYOBAMBA|MOYOBAMBA|DEPARTAMENTO SAN MARTIN	87,833	2,737.57	-6.000000	-77.000000	2
1741	220102	220102 - CALZADA|MOYOBAMBA|DEPARTAMENTO SAN MARTIN	4,435	95.38	-6.000000	-77.000000	2
1742	220103	220103 - HABANA|MOYOBAMBA|DEPARTAMENTO SAN MARTIN	2,078	91.25	-6.000000	-77.000000	2
1743	220104	220104 - JEPELACIO|MOYOBAMBA|DEPARTAMENTO SAN MARTIN	22,049	360.03	-6.000000	-77.000000	2
1744	220105	220105 - SORITOR|MOYOBAMBA|DEPARTAMENTO SAN MARTIN	35,837	387.76	-6.000000	-77.000000	2
1745	220106	220106 - YANTALO|MOYOBAMBA|DEPARTAMENTO SAN MARTIN	3,536	100.32	-6.000000	-77.000000	2
1746	220201	220201 - BELLAVISTA|BELLAVISTA|DEPARTAMENTO SAN MARTIN	13,643	287.12	-7.000000	-77.000000	2
1747	220202	220202 - ALTO BIAVO|BELLAVISTA|DEPARTAMENTO SAN MARTIN	7,368	6,117.12	-7.000000	-76.000000	2
1748	220203	220203 - BAJO BIAVO|BELLAVISTA|DEPARTAMENTO SAN MARTIN	20,617	975.43	-7.000000	-76.000000	2
1749	220204	220204 - HUALLAGA|BELLAVISTA|DEPARTAMENTO SAN MARTIN	3,118	210.42	-7.000000	-77.000000	2
1750	220205	220205 - SAN PABLO|BELLAVISTA|DEPARTAMENTO SAN MARTIN	9,128	362.49	-7.000000	-77.000000	2
1751	220206	220206 - SAN RAFAEL|BELLAVISTA|DEPARTAMENTO SAN MARTIN	7,706	98.32	-7.000000	-76.000000	2
1752	220301	220301 - SAN JOSE DE SISA|EL DORADO|DEPARTAMENTO SAN MARTIN	11,954	299.90	-7.000000	-77.000000	2
1753	220302	220302 - AGUA BLANCA|EL DORADO|DEPARTAMENTO SAN MARTIN	2,385	168.19	-7.000000	-77.000000	2
1754	220303	220303 - SAN MARTIN|EL DORADO|DEPARTAMENTO SAN MARTIN	13,834	562.57	-7.000000	-77.000000	2
1755	220304	220304 - SANTA ROSA|EL DORADO|DEPARTAMENTO SAN MARTIN	10,704	243.41	-7.000000	-77.000000	2
1756	220305	220305 - SHATOJA|EL DORADO|DEPARTAMENTO SAN MARTIN	3,281	24.07	-7.000000	-77.000000	2
1757	220401	220401 - SAPOSOA|HUALLAGA|DEPARTAMENTO SAN MARTIN	11,436	545.43	-7.000000	-77.000000	2
1758	220402	220402 - ALTO SAPOSOA|HUALLAGA|DEPARTAMENTO SAN MARTIN	3,296	1,347.34	-7.000000	-77.000000	2
1759	220403	220403 - EL ESLABON|HUALLAGA|DEPARTAMENTO SAN MARTIN	3,965	122.77	-7.000000	-77.000000	2
1760	220404	220404 - PISCOYACU|HUALLAGA|DEPARTAMENTO SAN MARTIN	3,958	184.87	-7.000000	-77.000000	2
1761	220405	220405 - SACANCHE|HUALLAGA|DEPARTAMENTO SAN MARTIN	2,602	143.15	-7.000000	-77.000000	2
1762	220406	220406 - TINGO DE SAPOSOA|HUALLAGA|DEPARTAMENTO SAN MARTIN	661	37.29	-7.000000	-77.000000	2
1763	220501	220501 - LAMAS|LAMAS|DEPARTAMENTO SAN MARTIN	12,528	79.82	-6.000000	-77.000000	2
1764	220502	220502 - ALONSO DE ALVARADO|LAMAS|DEPARTAMENTO SAN MARTIN	19,886	294.20	-6.000000	-77.000000	2
1765	220503	220503 - BARRANQUITA|LAMAS|DEPARTAMENTO SAN MARTIN	5,140	1,065.12	-6.000000	-76.000000	2
1766	220504	220504 - CAYNARACHI|LAMAS|DEPARTAMENTO SAN MARTIN	8,040	1,678.69	-6.000000	-76.000000	2
1767	220505	220505 - CUÑUMBUQUI|LAMAS|DEPARTAMENTO SAN MARTIN	4,815	191.46	-7.000000	-76.000000	2
1768	220506	220506 - PINTO RECODO|LAMAS|DEPARTAMENTO SAN MARTIN	11,115	524.07	-6.000000	-77.000000	2
1769	220507	220507 - RUMISAPA|LAMAS|DEPARTAMENTO SAN MARTIN	2,514	39.19	-6.000000	-76.000000	2
1770	220508	220508 - SAN ROQUE DE CUMBAZA|LAMAS|DEPARTAMENTO SAN MARTIN	1,466	525.15	-6.000000	-76.000000	2
1771	220509	220509 - SHANAO|LAMAS|DEPARTAMENTO SAN MARTIN	3,659	24.59	-6.000000	-77.000000	2
1772	220510	220510 - TABALOSOS|LAMAS|DEPARTAMENTO SAN MARTIN	13,492	485.25	-6.000000	-77.000000	2
1773	220511	220511 - ZAPATERO|LAMAS|DEPARTAMENTO SAN MARTIN	4,823	175.00	-7.000000	-76.000000	2
1774	220601	220601 - JUANJUI|MARISCAL CACERES|DEPARTAMENTO SAN MARTIN	26,662	335.19	-7.000000	-77.000000	2
1775	220602	220602 - CAMPANILLA|MARISCAL CACERES|DEPARTAMENTO SAN MARTIN	7,672	2,249.83	-7.000000	-77.000000	2
1776	220603	220603 - HUICUNGO|MARISCAL CACERES|DEPARTAMENTO SAN MARTIN	6,630	9,830.17	-7.000000	-77.000000	2
1777	220604	220604 - PACHIZA|MARISCAL CACERES|DEPARTAMENTO SAN MARTIN	4,205	1,839.51	-7.000000	-77.000000	2
1778	220605	220605 - PAJARILLO|MARISCAL CACERES|DEPARTAMENTO SAN MARTIN	6,192	244.03	-7.000000	-77.000000	2
1779	220701	220701 - PICOTA|PICOTA|DEPARTAMENTO SAN MARTIN	8,314	218.72	-7.000000	-76.000000	2
1780	220702	220702 - BUENOS AIRES|PICOTA|DEPARTAMENTO SAN MARTIN	3,287	272.97	-7.000000	-76.000000	2
1781	220703	220703 - CASPISAPA|PICOTA|DEPARTAMENTO SAN MARTIN	2,130	81.44	-7.000000	-76.000000	2
1782	220704	220704 - PILLUANA|PICOTA|DEPARTAMENTO SAN MARTIN	683	239.27	-7.000000	-76.000000	2
1783	220705	220705 - PUCACACA|PICOTA|DEPARTAMENTO SAN MARTIN	2,431	230.72	-7.000000	-76.000000	2
1784	220706	220706 - SAN CRISTOBAL|PICOTA|DEPARTAMENTO SAN MARTIN	1,427	29.63	-7.000000	-76.000000	2
1785	220707	220707 - SAN HILARION|PICOTA|DEPARTAMENTO SAN MARTIN	5,756	96.55	-7.000000	-76.000000	2
1786	220708	220708 - SHAMBOYACU|PICOTA|DEPARTAMENTO SAN MARTIN	12,188	415.58	-7.000000	-76.000000	2
1787	220709	220709 - TINGO DE PONASA|PICOTA|DEPARTAMENTO SAN MARTIN	4,889	340.01	-7.000000	-76.000000	2
1788	220710	220710 - TRES UNIDOS|PICOTA|DEPARTAMENTO SAN MARTIN	5,349	246.52	-7.000000	-76.000000	2
1789	220801	220801 - RIOJA|RIOJA|DEPARTAMENTO SAN MARTIN	24,222	185.69	-6.000000	-77.000000	2
1790	220802	220802 - AWAJUN|RIOJA|DEPARTAMENTO SAN MARTIN	12,342	481.08	-6.000000	-77.000000	2
1791	220803	220803 - ELIAS SOPLIN VARGAS|RIOJA|DEPARTAMENTO SAN MARTIN	13,897	199.64	-6.000000	-77.000000	2
1792	220804	220804 - NUEVA CAJAMARCA|RIOJA|DEPARTAMENTO SAN MARTIN	47,637	330.31	-6.000000	-77.000000	2
1793	220805	220805 - PARDO MIGUEL|RIOJA|DEPARTAMENTO SAN MARTIN	23,572	1,131.87	-6.000000	-78.000000	2
1794	220806	220806 - POSIC|RIOJA|DEPARTAMENTO SAN MARTIN	1,706	54.65	-6.000000	-77.000000	2
1795	220807	220807 - SAN FERNANDO|RIOJA|DEPARTAMENTO SAN MARTIN	3,360	63.53	-6.000000	-77.000000	2
1796	220808	220808 - YORONGOS|RIOJA|DEPARTAMENTO SAN MARTIN	3,741	74.53	-6.000000	-77.000000	2
1797	220809	220809 - YURACYACU|RIOJA|DEPARTAMENTO SAN MARTIN	3,914	13.74	-6.000000	-77.000000	2
1798	220901	220901 - TARAPOTO|SAN MARTIN|DEPARTAMENTO SAN MARTIN	75,656	67.81	-6.000000	-76.000000	2
1799	220902	220902 - ALBERTO LEVEAU|SAN MARTIN|DEPARTAMENTO SAN MARTIN	645	268.40	-7.000000	-76.000000	2
1800	220903	220903 - CACATACHI|SAN MARTIN|DEPARTAMENTO SAN MARTIN	3,466	75.36	-6.000000	-76.000000	2
1801	220904	220904 - CHAZUTA|SAN MARTIN|DEPARTAMENTO SAN MARTIN	8,206	966.38	-7.000000	-76.000000	2
1802	220905	220905 - CHIPURANA|SAN MARTIN|DEPARTAMENTO SAN MARTIN	1,818	500.44	-6.000000	-76.000000	2
1803	220906	220906 - EL PORVENIR|SAN MARTIN|DEPARTAMENTO SAN MARTIN	2,841	483.21	-6.000000	-76.000000	2
1804	220907	220907 - HUIMBAYOC|SAN MARTIN|DEPARTAMENTO SAN MARTIN	3,262	1,609.07	-6.000000	-76.000000	2
1805	220908	220908 - JUAN GUERRA|SAN MARTIN|DEPARTAMENTO SAN MARTIN	3,167	196.50	-7.000000	-76.000000	2
1806	220909	220909 - LA BANDA DE SHILCAYO|SAN MARTIN|DEPARTAMENTO SAN MARTIN	43,596	286.68	-7.000000	-76.000000	2
1807	220910	220910 - MORALES|SAN MARTIN|DEPARTAMENTO SAN MARTIN	30,844	43.91	-6.000000	-76.000000	2
1808	220911	220911 - PAPAPLAYA|SAN MARTIN|DEPARTAMENTO SAN MARTIN	1,975	686.19	-6.000000	-76.000000	2
1809	220912	220912 - SAN ANTONIO|SAN MARTIN|DEPARTAMENTO SAN MARTIN	1,345	93.03	-6.000000	-76.000000	2
1810	220913	220913 - SAUCE|SAN MARTIN|DEPARTAMENTO SAN MARTIN	16,808	103.00	-7.000000	-76.000000	2
1811	220914	220914 - SHAPAJA|SAN MARTIN|DEPARTAMENTO SAN MARTIN	1,472	270.44	-7.000000	-76.000000	2
1812	221001	221001 - TOCACHE|TOCACHE|DEPARTAMENTO SAN MARTIN	25,393	1,142.04	-8.000000	-77.000000	2
1813	221002	221002 - NUEVO PROGRESO|TOCACHE|DEPARTAMENTO SAN MARTIN	12,370	860.98	-8.000000	-76.000000	2
1814	221003	221003 - POLVORA|TOCACHE|DEPARTAMENTO SAN MARTIN	14,439	2,174.48	-8.000000	-77.000000	2
1815	221004	221004 - SHUNTE|TOCACHE|DEPARTAMENTO SAN MARTIN	983	964.21	-8.000000	-77.000000	2
1816	221005	221005 - UCHIZA|TOCACHE|DEPARTAMENTO SAN MARTIN	20,197	723.73	-8.000000	-76.000000	2
1817	230101	230101 - TACNA|TACNA|DEPARTAMENTO TACNA	80845	1,877.78	-18.000000	-70.000000	2
1818	230102	230102 - ALTO DE LA ALIANZA|TACNA|DEPARTAMENTO TACNA	40652	371.40	-18.000000	-70.000000	2
1819	230103	230103 - CALANA|TACNA|DEPARTAMENTO TACNA	3,338	108.38	-18.000000	-70.000000	2
1820	230104	230104 - CIUDAD NUEVA|TACNA|DEPARTAMENTO TACNA	39060	173.42	-18.000000	-70.000000	2
1821	230105	230105 - INCLAN|TACNA|DEPARTAMENTO TACNA	8125	1,414.82	-18.000000	-70.000000	2
1822	230106	230106 - PACHIA|TACNA|DEPARTAMENTO TACNA	1971	603.68	-18.000000	-70.000000	2
1823	230107	230107 - PALCA|TACNA|DEPARTAMENTO TACNA	1,728	1,417.86	-18.000000	-70.000000	2
1824	230108	230108 - POCOLLAY|TACNA|DEPARTAMENTO TACNA	22319	265.65	-18.000000	-70.000000	2
1825	230109	230109 - SAMA|TACNA|DEPARTAMENTO TACNA	2,679	1,115.98	-18.000000	-71.000000	2
1826	230110	230110 - CORONEL GREGORIO ALBARRACIN LANCHIPA|TACNA|DEPARTAMENTO TACNA	123662	187.74	-18.000000	-70.000000	2
1827	230111	230111 - LA YARADA-LOS PALOS|TACNA|DEPARTAMENTO TACNA	5,043	529.40	-18.000000	-70.000000	2
1828	230201	230201 - CANDARAVE|CANDARAVE|DEPARTAMENTO TACNA	3,008	1,111.03	-17.000000	-70.000000	2
1829	230202	230202 - CAIRANI|CANDARAVE|DEPARTAMENTO TACNA	1299	371.17	-17.000000	-70.000000	2
1830	230203	230203 - CAMILACA|CANDARAVE|DEPARTAMENTO TACNA	1468	518.65	-17.000000	-70.000000	2
1831	230204	230204 - CURIBAYA|CANDARAVE|DEPARTAMENTO TACNA	174	126.98	-17.000000	-70.000000	2
1832	230205	230205 - HUANUARA|CANDARAVE|DEPARTAMENTO TACNA	909	95.61	-17.000000	-70.000000	2
1833	230206	230206 - QUILAHUANI|CANDARAVE|DEPARTAMENTO TACNA	1229	37.66	-17.000000	-70.000000	2
1834	230301	230301 - LOCUMBA|JORGE BASADRE|DEPARTAMENTO TACNA	2641	968.99	-18.000000	-71.000000	2
1835	230302	230302 - ILABAYA|JORGE BASADRE|DEPARTAMENTO TACNA	2806	1,111.39	-17.000000	-71.000000	2
1836	230303	230303 - ITE|JORGE BASADRE|DEPARTAMENTO TACNA	3,415	848.18	-18.000000	-71.000000	2
1837	230401	230401 - TARATA|TARATA|DEPARTAMENTO TACNA	3,233	864.31	-17.000000	-70.000000	2
1838	230402	230402 - HEROES ALBARRACIN|TARATA|DEPARTAMENTO TACNA	676	372.41	-17.000000	-70.000000	2
1839	230403	230403 - ESTIQUE|TARATA|DEPARTAMENTO TACNA	741	312.85	-18.000000	-70.000000	2
1840	230404	230404 - ESTIQUE-PAMPA|TARATA|DEPARTAMENTO TACNA	703	185.61	-18.000000	-70.000000	2
1841	230405	230405 - SITAJARA|TARATA|DEPARTAMENTO TACNA	728	251.24	-17.000000	-70.000000	2
1842	230406	230406 - SUSAPAYA|TARATA|DEPARTAMENTO TACNA	746	373.21	-17.000000	-70.000000	2
1843	230407	230407 - TARUCACHI|TARATA|DEPARTAMENTO TACNA	412	113.27	-18.000000	-70.000000	2
1844	230408	230408 - TICACO|TARATA|DEPARTAMENTO TACNA	547	347.06	-17.000000	-70.000000	2
1845	240101	240101 - TUMBES|TUMBES|DEPARTAMENTO TUMBES	115,562	158.14	-4.000000	-80.000000	2
1846	240102	240102 - CORRALES|TUMBES|DEPARTAMENTO TUMBES	24,561	131.60	-4.000000	-80.000000	2
1847	240103	240103 - LA CRUZ|TUMBES|DEPARTAMENTO TUMBES	9,444	65.23	-4.000000	-81.000000	2
1848	240104	240104 - PAMPAS DE HOSPITAL|TUMBES|DEPARTAMENTO TUMBES	7,239	727.75	-4.000000	-80.000000	2
1849	240105	240105 - SAN JACINTO|TUMBES|DEPARTAMENTO TUMBES	8,704	598.72	-4.000000	-80.000000	2
1850	240106	240106 - SAN JUAN DE LA VIRGEN|TUMBES|DEPARTAMENTO TUMBES	4,160	118.71	-4.000000	-80.000000	2
1851	240201	240201 - ZORRITOS|CONTRALMIRANTE VILLA|DEPARTAMENTO TUMBES	12,785	644.52	-4.000000	-81.000000	2
1852	240202	240202 - CASITAS|CONTRALMIRANTE VILLA|DEPARTAMENTO TUMBES	2,088	855.36	-4.000000	-81.000000	2
1853	240203	240203 - CANOAS DE PUNTA SAL|CONTRALMIRANTE VILLA|DEPARTAMENTO TUMBES	5,700	623.34	-4.000000	-81.000000	2
1854	240301	240301 - ZARUMILLA|ZARUMILLA|DEPARTAMENTO TUMBES	23,148	113.25	-4.000000	-80.000000	2
1855	240302	240302 - AGUAS VERDES|ZARUMILLA|DEPARTAMENTO TUMBES	24,781	46.06	-3.000000	-80.000000	2
1856	240303	240303 - MATAPALO|ZARUMILLA|DEPARTAMENTO TUMBES	2,529	392.29	-4.000000	-80.000000	2
1857	240304	240304 - PAPAYAL|ZARUMILLA|DEPARTAMENTO TUMBES	5,348	193.53	-4.000000	-80.000000	2
1858	250101	250101 - CALLERIA|CORONEL PORTILLO|DEPARTAMENTO UCAYALI	159,364	10,485.41	-8.000000	-75.000000	2
1859	250102	250102 - CAMPOVERDE|CORONEL PORTILLO|DEPARTAMENTO UCAYALI	16,324	1,194.10	-8.000000	-75.000000	2
1860	250103	250103 - IPARIA|CORONEL PORTILLO|DEPARTAMENTO UCAYALI	12,193	8,029.12	-9.000000	-74.000000	2
1861	250104	250104 - MASISEA|CORONEL PORTILLO|DEPARTAMENTO UCAYALI	13,150	14,102.19	-9.000000	-74.000000	2
1862	250105	250105 - YARINACOCHA|CORONEL PORTILLO|DEPARTAMENTO UCAYALI	101,126	596.20	-8.000000	-75.000000	2
1863	250106	250106 - NUEVA REQUENA|CORONEL PORTILLO|DEPARTAMENTO UCAYALI	5,699	1,857.82	-8.000000	-75.000000	2
1864	250107	250107 - MANANTAY|CORONEL PORTILLO|DEPARTAMENTO UCAYALI	83,040	579.91	-8.000000	-75.000000	2
1865	250201	250201 - RAYMONDI|ATALAYA|DEPARTAMENTO UCAYALI	35,109	14,504.99	-11.000000	-74.000000	2
1866	250202	250202 - SEPAHUA|ATALAYA|DEPARTAMENTO UCAYALI	9,193	8,223.63	-11.000000	-73.000000	2
1867	250203	250203 - TAHUANIA|ATALAYA|DEPARTAMENTO UCAYALI	8,284	7,010.09	-10.000000	-74.000000	2
1868	250204	250204 - YURUA|ATALAYA|DEPARTAMENTO UCAYALI	2,716	9,175.58	-10.000000	-73.000000	2
1869	250301	250301 - PADRE ABAD|PADRE ABAD|DEPARTAMENTO UCAYALI	26,614	4,689.20	-9.000000	-76.000000	2
1870	250302	250302 - IRAZOLA|PADRE ABAD|DEPARTAMENTO UCAYALI	10,830	998.93	-9.000000	-75.000000	2
1871	250303	250303 - CURIMANA|PADRE ABAD|DEPARTAMENTO UCAYALI	8,956	2,134.04	-8.000000	-75.000000	2
1872	250304	250304 - NESHUYA|PADRE ABAD|DEPARTAMENTO UCAYALI	8,445	579.51	-9.000000	-75.000000	2
1873	250305	250305 - ALEXANDER VON HUMBOLDT|PADRE ABAD|DEPARTAMENTO UCAYALI	6,678	190.80	-9.000000	-75.000000	2
1874	250401	250401 - PURUS|PURUS|DEPARTAMENTO UCAYALI	4,657	17,847.76	-10.000000	-71.000000	2
\.


--
-- Data for Name: item_types; Type: TABLE DATA; Schema: master; Owner: postgres
--

COPY master.item_types (id, name, unit_code) FROM stdin;
2	PRODUCTO	NIU
1	SERVICIO	ZZ
\.


--
-- Data for Name: payment_types; Type: TABLE DATA; Schema: master; Owner: postgres
--

COPY master.payment_types (id, name, comment, is_active, status) FROM stdin;
1	Efectivo		0	2
2	Crédito	\N	0	2
3	Tarjeta		0	2
4	Cheque		0	2
5	Depósito		0	2
6	Otros	\N	0	2
7	Cupon		0	2
8	Transferencia	NULL	0	2
9	Yape	\N	0	2
10	Plin	\N	0	2
11	Culqui	\N	0	2
\.


--
-- Data for Name: shipment_transfer_reasons; Type: TABLE DATA; Schema: master; Owner: postgres
--

COPY master.shipment_transfer_reasons (id, name, code, status) FROM stdin;
1	VENTA	01	2
2	VENTA SUJETA A CONFIRMACION DEL COMPRADOR	14	2
3	COMPRA	02	2
4	TRASLADO ENTRE ESTABLECIMIENTOS DE LA MISMA EMPRESA	04	2
5	TRASLADO EMISOR ITINERANTE CP	18	2
6	IMPORTACION	08	2
7	EXPORTACION	09	2
8	TRASLADO A ZONA PRIMARIA\\r\\n	19	2
9	OTROS	13	2
\.


--
-- Data for Name: shipment_transport_modes; Type: TABLE DATA; Schema: master; Owner: postgres
--

COPY master.shipment_transport_modes (id, name, code, status) FROM stdin;
1	TRANSPORTE PUBLICO	01	2
2	TRANSPORTE PRIVADO	02	2
\.


--
-- Data for Name: sunat_uom; Type: TABLE DATA; Schema: master; Owner: postgres
--

COPY master.sunat_uom (id, code, name, is_active) FROM stdin;
1	4A	BOBINAS                                           	0
2	BJ	BALDE                                             	0
3	BLL	BARRILES                                          	0
4	BG	BOLSA                                             	0
5	BO	BOTELLAS                                          	1
6	BX	CAJA                                              	1
7	CT	CARTONES                                          	0
8	CMK	CENTIMETRO CUADRADO                               	0
9	CMQ	CENTIMETRO CUBICO                                 	0
10	CMT	CENTIMETRO LINEAL                                 	0
11	CEN	CIENTO DE UNIDADES                                	0
12	CY	CILINDRO                                          	0
13	CJ	CONOS                                             	0
14	DZN	DOCENA                                            	0
15	DZP	DOCENA POR 10**6                                  	0
16	BE	FARDO                                             	0
17	GLI	GALON INGLES (4,545956L)	0
18	GRM	GRAMO                                             	1
19	GRO	GRUESA                                            	0
20	HLT	HECTOLITRO                                        	0
21	LEF	HOJA                                              	0
22	SET	JUEGO                                             	0
23	KGM	KILOGRAMO                                         	1
24	KTM	KILOMETRO                                         	0
25	KWH	KILOVATIO HORA                                    	0
26	KT	KIT                                               	0
27	CA	LATAS                                             	0
28	LBR	LIBRAS                                            	0
29	LTR	LITRO                                             	1
30	MWH	MEGAWATT HORA                                     	0
31	MTR	METRO                                             	0
32	MTK	METRO CUADRADO                                    	0
33	MTQ	METRO CUBICO                                      	0
34	MGM	MILIGRAMOS                                        	0
35	MLT	MILILITRO                                         	0
36	MMT	MILIMETRO                                         	0
37	MMK	MILIMETRO CUADRADO                                	0
38	MMQ	MILIMETRO CUBICO                                  	0
39	MLL	MILLARES                                          	0
40	UM	MILLON DE UNIDADES                                	0
41	ONZ	ONZAS                                             	0
42	PF	PALETAS                                           	0
43	PK	PAQUETE                                           	0
44	PR	PAR                                               	0
45	FOT	PIES                                              	0
46	FTK	PIES CUADRADOS                                    	0
47	FTQ	PIES CUBICOS                                      	0
48	C62	PIEZAS                                            	0
49	PG	PLACAS                                            	0
50	ST	PLIEGO                                            	0
51	INH	PULGADAS                                          	0
52	RM	RESMA                                             	0
53	DR	TAMBOR                                            	0
54	STN	TONELADA CORTA                                    	0
55	LTN	TONELADA LARGA                                    	0
56	TNE	TONELADAS                                         	0
57	TU	TUBOS                                             	0
58	NIU	UNIDAD (BIENES)                                   	1
59	ZZ	UNIDAD (SERVICIOS) 	1
60	GLL	US GALON (3,7843 L)	0
61	YRD	YARDA                                             	0
62	YDK	YARDA CUADRADA                                    	0
63	VA	VARIOS	0
\.


--
-- Data for Name: tax_codes; Type: TABLE DATA; Schema: master; Owner: postgres
--

COPY master.tax_codes (code, description, international_code, short_name) FROM stdin;
\.


--
-- Data for Name: vat_categories; Type: TABLE DATA; Schema: master; Owner: postgres
--

COPY master.vat_categories (id, code, name, is_deleted, tax_code) FROM stdin;
1	10	Gravado - Operación Onerosa	0	1000
2	11	Gravado - Retiro por premio	0	9996
3	12	Gravado - Retiro por donación	0	9996
4	13	Gravado - Retiro	0	9996
5	14	Gravado - Retiro por publicidad	0	9996
6	15	Gravado - Bonificaciones	0	9996
7	16	Gravado - Retiro por entrega a trabajadores	0	9996
8	20	Exonerado - Operación Onerosa	0	9997
9	30	Inafecto - Operación Onerosa	0	9998
10	31	Inafecto - Retiro por Bonificación	0	9996
11	32	Inafecto - Retiro	0	9996
12	33	Inafecto - Retiro por Muestras Médicas	0	9996
13	34	Inafecto - Retiro por Convenio Colectivo	0	9996
14	35	Inafecto - Retiro por premio	0	9996
15	36	Inafecto - Retiro por publicidad	0	9996
16	40	Exportación	0	9996
17	17	Gravado - IVAP	0	9996
18	21	Exonerado - Transferencia gratuita	0	9996
19	37	Inafecto - Transferencia gratuita	0	9996
\.


--
-- Data for Name: failed_jobs; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.failed_jobs (id, connection, queue, payload, exception, failed_at) FROM stdin;
\.


--
-- Data for Name: migrations; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.migrations (id, migration, batch) FROM stdin;
1	2014_10_12_000000_create_users_table	1
2	2014_10_12_100000_create_password_resets_table	1
3	2019_08_19_000000_create_failed_jobs_table	1
4	2026_04_01_000001_create_detraccion_service_codes_table	1
5	2026_04_02_000001_create_tipo_clientes_table	1
6	2026_04_02_000002_complete_company_settings_data	1
7	2026_04_02_000003_add_tipo_cliente_codigo_to_sales_customers	1
8	2026_04_02_000004_create_sales_customer_types_and_link_customers	1
9	2026_04_02_000005_cleanup_old_customer_type_columns	1
10	2026_04_02_000006_create_company_igv_rates_table	1
\.


--
-- Data for Name: password_resets; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.password_resets (email, token, created_at) FROM stdin;
\.


--
-- Data for Name: users; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.users (id, name, email, email_verified_at, password, remember_token, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: cash_movements; Type: TABLE DATA; Schema: sales; Owner: postgres
--

COPY sales.cash_movements (id, cash_session_id, movement_type, payment_method_id, amount, ref_type, ref_id, notes, created_by, created_at, company_id, branch_id, cash_register_id, description, user_id, movement_at) FROM stdin;
2	3	INCOME	1	11.80	COMMERCIAL_DOCUMENT	18	Cobro doc RECEIPT B001-8	1	2026-03-15 00:22:27-05	1	1	1	Cobro doc RECEIPT B001-8	1	2026-03-15 00:22:27-05
3	4	INCOME	1	11.80	COMMERCIAL_DOCUMENT	19	Cobro doc INVOICE F001-4	1	2026-03-19 03:51:20-05	1	1	1	Cobro doc INVOICE F001-4	1	2026-03-19 03:51:20-05
4	7	INCOME	1	11.80	COMMERCIAL_DOCUMENT	20	Cobro doc INVOICE F001-5	1	2026-03-19 04:47:29-05	1	1	1	Cobro doc INVOICE F001-5	1	2026-03-19 04:47:29-05
5	7	INCOME	1	11.80	COMMERCIAL_DOCUMENT	21	Cobro doc RECEIPT B001-9	1	2026-03-19 20:58:13-05	1	1	1	Cobro doc RECEIPT B001-9	1	2026-03-19 20:58:13-05
6	7	INCOME	1	11.80	COMMERCIAL_DOCUMENT	22	Cobro doc RECEIPT B001-10	1	2026-03-19 21:12:27-05	1	1	1	Cobro doc RECEIPT B001-10	1	2026-03-19 21:12:27-05
7	7	INCOME	1	11.80	COMMERCIAL_DOCUMENT	27	Cobro doc INVOICE F001-7	2	2026-03-20 19:22:49-05	1	1	1	Cobro doc INVOICE F001-7	2	2026-03-20 19:22:49-05
8	7	INCOME	1	11.80	COMMERCIAL_DOCUMENT	28	Cobro doc RECEIPT B001-11	1	2026-03-21 13:40:46-05	1	1	1	Cobro doc RECEIPT B001-11	1	2026-03-21 13:40:46-05
9	8	INCOME	1	30.00	COMMERCIAL_DOCUMENT	38	Cobro doc Boleta B001-14	1	2026-04-04 10:39:09-05	1	1	1	Cobro doc Boleta B001-14	1	2026-04-04 10:39:09-05
10	8	INCOME	1	100.00	COMMERCIAL_DOCUMENT	39	Cobro doc Boleta B001-15	1	2026-04-04 10:59:16-05	1	1	1	Cobro doc Boleta B001-15	1	2026-04-04 10:59:16-05
11	8	INCOME	1	150.00	COMMERCIAL_DOCUMENT	40	Cobro doc Factura F001-11	1	2026-04-04 11:07:31-05	1	1	1	Cobro doc Factura F001-11	1	2026-04-04 11:07:31-05
\.


--
-- Data for Name: cash_registers; Type: TABLE DATA; Schema: sales; Owner: postgres
--

COPY sales.cash_registers (id, company_id, branch_id, code, name, status, created_at) FROM stdin;
1	1	1	L01	CAJA 01	1	2026-03-11 21:24:04-05
\.


--
-- Data for Name: cash_sessions; Type: TABLE DATA; Schema: sales; Owner: postgres
--

COPY sales.cash_sessions (id, company_id, branch_id, cash_register_id, opened_by, closed_by, opened_at, closed_at, opening_balance, closing_balance, expected_balance, difference_amount, status, notes, user_id, created_at) FROM stdin;
3	1	1	1	1	1	2026-03-15 00:13:59-05	2026-03-19 03:23:31-05	10.00	21.80	21.80	\N	CLOSED	\N	1	2026-03-15 00:13:59-05
4	1	1	1	1	1	2026-03-19 03:50:54-05	2026-03-19 03:51:48-05	0.00	11.80	11.80	\N	CLOSED	\N	1	2026-03-19 03:50:54-05
5	1	1	1	1	1	2026-03-19 04:02:55-05	2026-03-19 04:06:54-05	0.00	0.00	0.00	\N	CLOSED	\N	1	2026-03-19 04:02:55-05
6	1	1	1	1	1	2026-03-19 04:24:52-05	2026-03-19 04:34:10-05	0.00	0.00	0.00	\N	CLOSED	\N	1	2026-03-19 04:24:52-05
7	1	1	1	1	1	2026-03-19 04:35:47-05	2026-03-21 13:42:55-05	0.00	59.00	59.00	\N	CLOSED	\N	1	2026-03-19 04:35:47-05
8	1	1	1	1	\N	2026-03-21 20:06:22-05	\N	0.00	\N	280.00	\N	OPEN	\N	1	2026-03-21 20:06:22-05
\.


--
-- Data for Name: commercial_document_item_lots; Type: TABLE DATA; Schema: sales; Owner: postgres
--

COPY sales.commercial_document_item_lots (id, document_item_id, lot_id, qty, created_at) FROM stdin;
1	32	1	10.000	2026-03-26 04:46:44-05
2	33	1	10.000	2026-03-26 04:50:34-05
3	34	1	10.000	2026-03-26 04:52:03-05
4	35	1	1.000	2026-04-04 10:39:09-05
5	37	1	5.000	2026-04-04 11:07:31-05
\.


--
-- Data for Name: commercial_document_items; Type: TABLE DATA; Schema: sales; Owner: postgres
--

COPY sales.commercial_document_items (id, document_id, line_no, product_id, unit_id, price_tier_id, tax_category_id, description, qty, qty_base, conversion_factor, base_unit_price, unit_price, unit_cost, wholesale_discount_percent, price_source, discount_total, tax_total, subtotal, total, metadata) FROM stdin;
1	5	1	1	58	\N	1	SIN-SKU - PRODUCTO1	1.000	\N	1.00000000	\N	10.0000	0.0000	0.0000	MANUAL	0.00	1.80	10.00	11.80	\N
2	5	2	\N	58	\N	1	ASAS	1.000	\N	1.00000000	\N	10.0000	0.0000	0.0000	MANUAL	0.00	1.80	10.00	11.80	\N
3	6	1	1	58	\N	1	SIN-SKU - PRODUCTO1	1.000	\N	1.00000000	\N	10.0000	0.0000	0.0000	MANUAL	0.00	1.80	10.00	11.80	\N
9	12	1	1	58	\N	1	SIN-SKU - PRODUCTO1	5.000	5.000	1.00000000	10.0000	10.0000	0.0000	0.0000	MANUAL	0.00	9.00	50.00	59.00	\N
10	13	1	1	58	\N	1	SIN-SKU - PRODUCTO1	1.000	1.000	1.00000000	10.0000	10.0000	0.0000	0.0000	MANUAL	0.00	1.80	10.00	11.80	\N
11	14	1	1	58	\N	1	SIN-SKU - PRODUCTO1	2.000	2.000	1.00000000	10.0000	10.0000	0.0000	0.0000	MANUAL	0.00	3.60	20.00	23.60	\N
12	15	1	1	58	\N	1	SIN-SKU - PRODUCTO1	1.000	1.000	1.00000000	15.0000	15.0000	0.0000	0.0000	MANUAL	0.00	2.70	15.00	17.70	\N
13	16	1	\N	58	\N	1	ASFDF	1.000	1.000	1.00000000	12.0000	12.0000	0.0000	0.0000	MANUAL	0.00	2.16	12.00	14.16	\N
15	18	1	1	58	\N	1	SIN-SKU - PRODUCTO1	1.000	1.000	1.00000000	10.0000	10.0000	0.0000	0.0000	MANUAL	0.00	1.80	10.00	11.80	\N
16	19	1	1	58	\N	1	SIN-SKU - PRODUCTO1	1.000	1.000	1.00000000	10.0000	10.0000	0.0000	0.0000	MANUAL	0.00	1.80	10.00	11.80	\N
17	20	1	1	58	\N	1	SIN-SKU - PRODUCTO1	1.000	1.000	1.00000000	10.0000	10.0000	0.0000	0.0000	MANUAL	0.00	1.80	10.00	11.80	\N
18	21	1	1	58	\N	1	SIN-SKU - PRODUCTO1	1.000	1.000	1.00000000	10.0000	10.0000	0.0000	0.0000	MANUAL	0.00	1.80	10.00	11.80	\N
19	22	1	1	58	\N	1	SIN-SKU - PRODUCTO1	1.000	1.000	1.00000000	10.0000	10.0000	0.0000	0.0000	MANUAL	0.00	1.80	10.00	11.80	\N
20	23	1	1	58	\N	\N	SIN-SKU - PRODUCTO1	1.000	1.000	1.00000000	10.0000	10.0000	0.0000	0.0000	MANUAL	0.00	0.00	10.00	10.00	\N
21	24	1	1	58	\N	\N	SIN-SKU - PRODUCTO1	1.000	1.000	1.00000000	10.0000	10.0000	0.0000	0.0000	MANUAL	0.00	0.00	10.00	10.00	\N
22	25	1	1	58	\N	\N	SIN-SKU - PRODUCTO1	1.000	1.000	1.00000000	10.0000	10.0000	0.0000	0.0000	MANUAL	0.00	0.00	10.00	10.00	\N
23	26	1	1	58	\N	\N	SIN-SKU - PRODUCTO1	1.000	1.000	1.00000000	10.0000	10.0000	0.0000	0.0000	MANUAL	0.00	0.00	10.00	10.00	\N
24	27	1	1	58	\N	1	SIN-SKU - PRODUCTO1	1.000	1.000	1.00000000	10.0000	10.0000	0.0000	0.0000	MANUAL	0.00	1.80	10.00	11.80	\N
25	28	1	1	58	\N	1	SIN-SKU - PRODUCTO1	1.000	1.000	1.00000000	10.0000	10.0000	0.0000	0.0000	MANUAL	0.00	1.80	10.00	11.80	\N
26	29	1	1	58	\N	\N	SIN-SKU - PRODUCTO1	1.000	1.000	1.00000000	10.0000	10.0000	0.0000	0.0000	MANUAL	0.00	0.00	10.00	10.00	\N
27	30	1	1	58	\N	\N	SIN-SKU - PRODUCTO1	1.000	1.000	1.00000000	10.0000	10.0000	0.0000	0.0000	MANUAL	0.00	0.00	10.00	10.00	\N
28	31	1	1	58	\N	1	SIN-SKU - PRODUCTO1	1.000	1.000	1.00000000	10.0000	10.0000	0.0000	0.0000	MANUAL	0.00	1.80	10.00	11.80	\N
29	32	1	1	58	\N	\N	SIN-SKU - PRODUCTO1	1.000	1.000	1.00000000	10.0000	10.0000	0.0000	0.0000	MANUAL	0.00	0.00	10.00	10.00	\N
30	33	1	1	58	\N	\N	SIN-SKU - PRODUCTO1	1.000	1.000	1.00000000	10.0000	10.0000	0.0000	0.0000	MANUAL	0.00	0.00	10.00	10.00	\N
31	34	1	1	58	\N	\N	SIN-SKU - PRODUCTO1	1.000	1.000	1.00000000	10.0000	10.0000	0.0000	0.0000	MANUAL	0.00	0.00	10.00	10.00	\N
32	35	1	3	58	\N	1	SIN-SKU - PRODUCTO2	10.000	10.000	1.00000000	30.0000	30.0000	0.0000	0.0000	MANUAL	0.00	54.00	300.00	354.00	\N
33	36	1	3	58	\N	\N	SIN-SKU - PRODUCTO2	10.000	10.000	1.00000000	30.0000	30.0000	0.0000	0.0000	MANUAL	0.00	0.00	300.00	300.00	\N
34	37	1	3	58	\N	\N	SIN-SKU - PRODUCTO2	10.000	10.000	1.00000000	30.0000	30.0000	0.0000	0.0000	MANUAL	0.00	0.00	300.00	300.00	\N
35	38	1	3	58	\N	1	SIN-SKU - PRODUCTO2	1.000	1.000	1.00000000	25.4237	30.0000	0.0000	0.0000	MANUAL	0.00	4.58	25.42	30.00	"{\\"price_includes_tax\\":true}"
36	39	1	1	58	\N	1	SIN-SKU - PRODUCTO1	10.000	10.000	1.00000000	8.4746	10.0000	0.0000	0.0000	MANUAL	0.00	15.25	84.75	100.00	"{\\"price_includes_tax\\":true}"
37	40	1	3	58	\N	1	SIN-SKU - PRODUCTO2	5.000	5.000	1.00000000	25.4237	30.0000	0.0000	0.0000	MANUAL	0.00	22.88	127.12	150.00	"{\\"price_includes_tax\\":true}"
\.


--
-- Data for Name: commercial_document_payments; Type: TABLE DATA; Schema: sales; Owner: postgres
--

COPY sales.commercial_document_payments (id, document_id, payment_method_id, amount, due_at, paid_at, status, notes, created_at) FROM stdin;
1	1	1	24.78	\N	2026-03-10 23:09:35.368-05	PAID	\N	2026-03-11 04:09:35-05
2	2	1	194.70	\N	2026-03-11 16:53:39.672-05	PAID	\N	2026-03-11 21:53:39-05
3	3	1	81.00	\N	2026-03-11 21:15:41.568-05	PAID	\N	2026-03-12 02:15:41-05
4	4	1	10.50	\N	2026-03-11 21:16:21.808-05	PAID	\N	2026-03-12 02:16:21-05
5	5	1	23.60	\N	2026-03-11 21:58:21.241-05	PAID	\N	2026-03-12 02:58:21-05
6	6	1	11.80	\N	2026-03-11 22:04:19.367-05	PAID	\N	2026-03-12 03:04:19-05
7	12	1	59.00	\N	2026-03-14 09:14:13.046-05	PAID	\N	2026-03-14 14:14:13-05
8	13	1	11.80	\N	2026-03-14 09:14:51.254-05	PAID	\N	2026-03-14 14:14:51-05
9	14	1	23.60	\N	2026-03-14 09:21:23.751-05	PAID	\N	2026-03-14 14:21:23-05
10	15	1	17.70	\N	2026-03-14 13:11:12.085-05	PAID	\N	2026-03-14 18:11:12-05
11	16	1	14.16	\N	2026-03-14 19:15:05.168-05	PAID	\N	2026-03-15 00:15:05-05
13	18	1	11.80	\N	2026-03-14 19:22:27.16-05	PAID	\N	2026-03-15 00:22:27-05
14	19	1	11.80	\N	2026-03-18 22:51:20.106-05	PAID	\N	2026-03-19 03:51:20-05
15	20	1	11.80	\N	2026-03-18 23:47:29.105-05	PAID	\N	2026-03-19 04:47:29-05
16	21	1	11.80	\N	2026-03-19 15:58:13.495-05	PAID	\N	2026-03-19 20:58:13-05
17	22	1	11.80	\N	2026-03-19 16:12:27.055-05	PAID	\N	2026-03-19 21:12:27-05
18	27	1	11.80	\N	2026-03-20 14:22:48.731-05	PAID	\N	2026-03-20 19:22:49-05
19	28	1	11.80	\N	2026-03-21 08:40:46.67-05	PAID	\N	2026-03-21 13:40:46-05
20	31	1	11.80	\N	2026-03-21 15:14:17.545-05	PAID	\N	2026-03-21 20:14:17-05
21	35	1	354.00	\N	2026-03-25 23:46:44.303-05	PAID	\N	2026-03-26 04:46:44-05
22	38	1	30.00	\N	2026-04-04 10:39:09.375-05	PAID	\N	2026-04-04 10:39:09-05
23	39	1	100.00	\N	2026-04-04 10:59:16.432-05	PAID	\N	2026-04-04 10:59:16-05
24	40	1	150.00	\N	2026-04-04 11:07:31.129-05	PAID	\N	2026-04-04 11:07:31-05
\.


--
-- Data for Name: commercial_documents; Type: TABLE DATA; Schema: sales; Owner: postgres
--

COPY sales.commercial_documents (id, company_id, branch_id, warehouse_id, document_kind, series, number, issue_at, due_at, customer_id, currency_id, payment_method_id, exchange_rate, source_document_id, reference_document_id, reference_reason_code, tax_affectation_code, seller_user_id, subtotal, tax_total, total, paid_total, balance_due, discount_total, status, external_status, notes, metadata, created_by, updated_by, created_at, updated_at, deleted_at) FROM stdin;
1	1	1	\N	INVOICE	F001	1	2026-03-10 23:09:35.368-05	\N	1	1	1	\N	\N	\N	\N	\N	1	21.00	3.78	24.78	24.78	0.00	0.00	ISSUED	\N	\N	\N	1	1	2026-03-11 04:09:35-05	2026-03-11 04:09:35-05	\N
2	1	1	1	RECEIPT	B001	2	2026-03-11 16:53:39.672-05	\N	1	1	1	\N	\N	\N	\N	\N	1	165.00	29.70	194.70	194.70	0.00	0.00	ISSUED	\N	\N	{"cash_register_id": 1}	1	1	2026-03-11 21:53:39-05	2026-03-11 21:53:39-05	\N
3	1	1	1	QUOTATION	C001	2	2026-03-11 21:15:41.568-05	\N	1	1	1	\N	\N	\N	\N	\N	1	81.00	0.00	81.00	81.00	0.00	0.00	ISSUED	\N	\N	{"cash_register_id": 1}	1	1	2026-03-12 02:15:41-05	2026-03-12 02:15:41-05	\N
4	1	1	1	QUOTATION	C001	3	2026-03-11 21:16:21.808-05	\N	1	1	1	\N	\N	\N	\N	\N	1	10.50	0.00	10.50	10.50	0.00	0.00	ISSUED	\N	\N	{"cash_register_id": 1}	1	1	2026-03-12 02:16:21-05	2026-03-12 02:16:21-05	\N
5	1	1	1	RECEIPT	B001	3	2026-03-11 21:58:21.241-05	\N	1	1	1	\N	\N	\N	\N	\N	1	20.00	3.60	23.60	23.60	0.00	0.00	ISSUED	\N	\N	{"cash_register_id": 1}	1	1	2026-03-12 02:58:21-05	2026-03-12 02:58:21-05	\N
6	1	1	1	RECEIPT	B001	4	2026-03-12 03:04:19-05	\N	2	1	1	\N	\N	\N	\N	\N	1	10.00	1.80	11.80	11.80	0.00	0.00	ISSUED	\N	\N	{"cash_register_id": 1, "customer_address": "LIMA"}	1	1	2026-03-12 03:04:19-05	2026-03-12 03:04:19-05	\N
12	1	1	1	INVOICE	F001	2	2026-03-14 00:00:00-05	2026-03-14 00:00:00-05	1	1	1	\N	\N	\N	\N	\N	1	50.00	9.00	59.00	59.00	0.00	0.00	ISSUED	\N	\N	{"cash_register_id": 1, "customer_address": "DIRECCION DEMO"}	1	1	2026-03-14 14:14:13-05	2026-03-14 14:14:13-05	\N
13	1	1	1	INVOICE	F001	3	2026-03-14 00:00:00-05	2026-03-14 00:00:00-05	1	1	1	\N	\N	\N	\N	\N	1	10.00	1.80	11.80	11.80	0.00	0.00	ISSUED	\N	\N	{"cash_register_id": 1, "customer_address": "DIRECCION DEMO"}	1	1	2026-03-14 14:14:51-05	2026-03-14 14:14:51-05	\N
14	1	1	1	RECEIPT	B001	5	2026-03-14 00:00:00-05	2026-03-14 00:00:00-05	1	1	1	\N	\N	\N	\N	\N	1	20.00	3.60	23.60	23.60	0.00	0.00	ISSUED	\N	\N	{"cash_register_id": 1, "customer_address": "DIRECCION DEMO"}	1	1	2026-03-14 14:21:23-05	2026-03-14 14:21:23-05	\N
15	1	1	1	RECEIPT	B001	6	2026-03-14 00:00:00-05	2026-03-14 00:00:00-05	1	1	1	\N	\N	\N	\N	\N	1	15.00	2.70	17.70	17.70	0.00	0.00	ISSUED	\N	\N	{"cash_register_id": 1, "customer_address": "DIRECCION DEMO"}	1	1	2026-03-14 18:11:12-05	2026-03-14 18:11:12-05	\N
16	1	1	1	RECEIPT	B001	7	2026-03-14 00:00:00-05	2026-03-14 00:00:00-05	1	1	1	\N	\N	\N	\N	\N	1	12.00	2.16	14.16	14.16	0.00	0.00	ISSUED	\N	\N	{"cash_register_id": 1, "customer_address": "DIRECCION DEMO"}	1	1	2026-03-15 00:15:05-05	2026-03-15 00:15:05-05	\N
18	1	1	1	RECEIPT	B001	8	2026-03-14 00:00:00-05	2026-03-14 00:00:00-05	1	1	1	\N	\N	\N	\N	\N	1	10.00	1.80	11.80	11.80	0.00	0.00	ISSUED	\N	\N	{"cash_register_id": 1, "customer_address": "DIRECCION DEMO"}	1	1	2026-03-15 00:22:27-05	2026-03-15 00:22:27-05	\N
19	1	1	1	INVOICE	F001	4	2026-03-18 00:00:00-05	2026-03-18 00:00:00-05	1	1	1	\N	\N	\N	\N	\N	1	10.00	1.80	11.80	11.80	0.00	0.00	ISSUED	\N	\N	{"cash_register_id": 1, "customer_address": "DIRECCION DEMO"}	1	1	2026-03-19 03:51:20-05	2026-03-19 03:51:20-05	\N
20	1	1	1	INVOICE	F001	5	2026-03-18 00:00:00-05	2026-03-18 00:00:00-05	1	1	1	\N	\N	\N	\N	\N	1	10.00	1.80	11.80	11.80	0.00	0.00	ISSUED	\N	\N	{"cash_register_id": 1, "customer_address": "DIRECCION DEMO"}	1	1	2026-03-19 04:47:29-05	2026-03-19 04:47:29-05	\N
21	1	1	1	RECEIPT	B001	9	2026-03-19 00:00:00-05	2026-03-19 00:00:00-05	1	1	1	\N	\N	\N	\N	\N	1	10.00	1.80	11.80	11.80	0.00	0.00	ISSUED	\N	\N	{"cash_register_id": 1, "customer_address": "DIRECCION DEMO"}	1	1	2026-03-19 20:58:13-05	2026-03-19 20:58:13-05	\N
22	1	1	1	RECEIPT	B001	10	2026-03-19 00:00:00-05	2026-03-19 00:00:00-05	1	1	1	\N	\N	\N	\N	\N	1	10.00	1.80	11.80	11.80	0.00	0.00	ISSUED	\N	\N	{"cash_register_id": 1, "customer_address": "DIRECCION DEMO"}	1	1	2026-03-19 21:12:27-05	2026-03-19 21:12:27-05	\N
23	1	1	1	SALES_ORDER	P001	2	2026-03-20 00:00:00-05	2026-03-20 00:00:00-05	1	1	1	\N	\N	\N	\N	\N	1	10.00	0.00	10.00	0.00	10.00	0.00	DRAFT	\N	\N	{"cash_register_id": null, "customer_address": "DIRECCION DEMO"}	1	1	2026-03-20 16:58:06-05	2026-03-20 16:58:06-05	\N
24	1	1	1	SALES_ORDER	P001	3	2026-03-20 00:00:00-05	2026-03-20 00:00:00-05	1	1	1	\N	\N	\N	\N	\N	2	10.00	0.00	10.00	0.00	10.00	0.00	DRAFT	\N	\N	{"cash_register_id": null, "customer_address": "DIRECCION DEMO"}	2	2	2026-03-20 17:17:19-05	2026-03-20 17:17:19-05	\N
25	1	1	1	QUOTATION	C001	4	2026-03-20 00:00:00-05	2026-03-20 00:00:00-05	1	1	1	\N	\N	\N	\N	\N	2	10.00	0.00	10.00	0.00	10.00	0.00	DRAFT	\N	\N	{"cash_register_id": null, "customer_address": "DIRECCION DEMO"}	2	2	2026-03-20 18:56:32-05	2026-03-20 18:56:32-05	\N
26	1	1	1	INVOICE	F001	6	2026-03-20 13:57:38.92-05	2026-03-20 00:00:00-05	1	1	1	\N	\N	\N	\N	\N	3	10.00	0.00	10.00	0.00	10.00	0.00	ISSUED	\N	\N	{"cash_register_id": 1, "customer_address": "DIRECCION DEMO", "conversion_origin": "SALES_MODULE", "source_document_id": 25, "source_document_kind": "QUOTATION", "source_document_number": "C001-4", "stock_already_discounted": false}	3	3	2026-03-20 18:57:39-05	2026-03-20 18:57:39-05	\N
27	1	1	1	INVOICE	F001	7	2026-03-20 00:00:00-05	2026-03-20 00:00:00-05	1	1	1	\N	\N	\N	\N	\N	2	10.00	1.80	11.80	11.80	0.00	0.00	ISSUED	\N	\N	{"cash_register_id": 1, "customer_address": "DIRECCION DEMO"}	2	2	2026-03-20 19:22:49-05	2026-03-20 19:22:49-05	\N
28	1	1	1	RECEIPT	B001	11	2026-03-21 00:00:00-05	2026-03-21 00:00:00-05	1	1	1	\N	\N	\N	\N	\N	1	10.00	1.80	11.80	11.80	0.00	0.00	ISSUED	\N	\N	{"cash_register_id": 1, "customer_address": "DIRECCION DEMO"}	1	1	2026-03-21 13:40:46-05	2026-03-21 13:40:46-05	\N
29	1	1	1	QUOTATION	C001	5	2026-03-21 00:00:00-05	2026-03-21 00:00:00-05	1	1	1	\N	\N	\N	\N	\N	2	10.00	0.00	10.00	0.00	10.00	0.00	DRAFT	\N	\N	{"cash_register_id": null, "customer_address": "DIRECCION DEMO"}	2	2	2026-03-21 13:46:29-05	2026-03-21 13:46:29-05	\N
30	1	1	1	INVOICE	F001	8	2026-03-21 08:47:26.161-05	2026-03-21 00:00:00-05	1	1	1	\N	\N	\N	\N	\N	3	10.00	0.00	10.00	0.00	10.00	0.00	ISSUED	\N	\N	{"cash_register_id": 1, "customer_address": "DIRECCION DEMO", "conversion_origin": "SALES_MODULE", "source_document_id": 29, "source_document_kind": "QUOTATION", "source_document_number": "C001-5", "stock_already_discounted": false}	3	3	2026-03-21 13:47:26-05	2026-03-21 13:47:26-05	\N
31	1	1	1	RECEIPT	B001	12	2026-03-21 00:00:00-05	2026-03-21 00:00:00-05	1	1	1	\N	\N	\N	\N	\N	1	10.00	1.80	11.80	11.80	0.00	0.00	ISSUED	\N	\N	{"cash_register_id": null, "customer_address": "DIRECCION DEMO"}	1	1	2026-03-21 20:14:17-05	2026-03-21 20:14:17-05	\N
32	1	1	1	QUOTATION	C001	6	2026-03-21 00:00:00-05	2026-03-21 00:00:00-05	1	1	1	\N	\N	\N	\N	\N	2	10.00	0.00	10.00	0.00	10.00	0.00	DRAFT	\N	\N	{"cash_register_id": null, "customer_address": "DIRECCION DEMO"}	2	2	2026-03-21 20:16:27-05	2026-03-21 20:16:27-05	\N
33	1	1	1	QUOTATION	C001	7	2026-03-21 00:00:00-05	2026-03-21 00:00:00-05	1	1	1	\N	\N	\N	\N	\N	2	10.00	0.00	10.00	0.00	10.00	0.00	DRAFT	\N	\N	{"cash_register_id": null, "customer_address": "DIRECCION DEMO"}	2	2	2026-03-21 20:18:03-05	2026-03-21 20:18:03-05	\N
34	1	1	1	INVOICE	F001	9	2026-03-21 15:21:12.072-05	2026-03-21 00:00:00-05	1	1	1	\N	\N	\N	\N	\N	3	10.00	0.00	10.00	0.00	10.00	0.00	ISSUED	\N	\N	{"cash_register_id": 1, "customer_address": "DIRECCION DEMO", "conversion_origin": "SALES_MODULE", "source_document_id": 32, "source_document_kind": "QUOTATION", "source_document_number": "C001-6", "stock_already_discounted": false}	3	3	2026-03-21 20:21:12-05	2026-03-21 20:21:12-05	\N
35	1	1	1	RECEIPT	B001	13	2026-03-25 00:00:00-05	2026-03-25 00:00:00-05	1	1	1	\N	\N	\N	\N	\N	1	300.00	54.00	354.00	354.00	0.00	0.00	ISSUED	\N	\N	{"cash_register_id": null, "customer_address": "DIRECCION DEMO"}	1	1	2026-03-26 04:46:44-05	2026-03-26 04:46:44-05	\N
36	1	1	1	QUOTATION	C001	8	2026-03-25 00:00:00-05	2026-03-25 00:00:00-05	1	1	1	\N	\N	\N	\N	\N	2	300.00	0.00	300.00	0.00	300.00	0.00	DRAFT	\N	\N	{"cash_register_id": null, "customer_address": "DIRECCION DEMO"}	2	2	2026-03-26 04:50:34-05	2026-03-26 04:50:34-05	\N
37	1	1	1	INVOICE	F001	10	2026-03-25 23:52:03.694-05	2026-03-25 00:00:00-05	1	1	1	\N	\N	\N	\N	\N	3	300.00	0.00	300.00	0.00	300.00	0.00	ISSUED	\N	\N	{"cash_register_id": 1, "customer_address": "DIRECCION DEMO", "conversion_origin": "SALES_MODULE", "source_document_id": 36, "source_document_kind": "QUOTATION", "source_document_number": "C001-8", "stock_already_discounted": false}	3	3	2026-03-26 04:52:03-05	2026-03-26 04:52:03-05	\N
40	1	1	1	INVOICE	F001	11	2026-04-04 06:07:31-05	2026-04-03 19:00:00-05	2	1	1	\N	\N	\N	\N	\N	1	127.12	22.88	150.00	150.00	0.00	0.00	ISSUED	\N	\N	{"sunat_status": "ACCEPTED", "sunat_bridge_mode": "BETA", "sunat_last_sync_at": "2026-04-04 16:07:32", "sunat_status_label": "Aceptado", "sunat_bridge_method": "POST", "sunat_bridge_request": {"form_key": "datosJSON", "payload_json": {"Pago": {"Monto": 150, "FormaPago": "Contado"}, "letra": "Ciento cincuenta con 00/100 SOL", "adjunto": null, "cliente": {"ruc": "10455923951", "razon_social": "FERNANDEZ DE LA CRUZ", "tipo_documento": "6"}, "detalle": [{"igv": 22.88, "base": 127.12, "sunat": "", "codigo": "3", "icbper": "off", "unidad": "NIU", "cantidad": 5, "tipo_igv": "10", "descuento": 0, "gratuitas": 0, "impuestos": 22.88, "igv_icbper": 0, "descripcion": "SIN-SKU - PRODUCTO2", "valor_venta": 127.12, "valor_icbper": 0, "porcentaje_igv": 18, "valor_unitario": 25.424, "precio_unitario": 30}], "empresa": {"ruc": "10455923951", "pass": "***", "user": "MODDATOS", "correo": "info@empresademo.com", "ubigeo": "150131", "distrito": "SAN ISIDRO", "direccion": "AV. PRINCIPAL 123", "envio_pse": "", "provincia": "LIMA", "codigolocal": "", "departamento": "LIMA", "razon_social": "MSEP PERU SAC", "urbanizacion": "ORRANTIA", "telefono_fijo": "+51 1 2345678", "nombre_comercial": "DEMO"}, "cabecera": {"igv": 22.88, "serie": "F001", "icbper": 0, "numero": "11", "gravadas": 127.12, "impuestos": 22.88, "inafectas": 0, "cod_motivo": "", "des_motivo": "", "exoneradas": 0, "tipo_moneda": "PEN", "fecha_emision": "2026-04-04 11:07:31", "importe_venta": 150, "observaciones": "", "tipo_documento": "01", "tipo_operacion": "0101", "descuentoGlobal": 0}, "Retencion": {"Estado": "off", "TotalRetencion": 0, "CodigoRetencion": "", "PorcentajeRetencion": 0}, "Detraccion": {"Estado": "off", "CodigoMedioPago": "001", "TotalDetraccion": 0, "CodigoDetraccion": "", "PorcentajeDetraccion": 0, "NumeroCuentaDetraccion": ""}, "Percepcion": {"Estado": "off", "TotalPercepcion": 0, "CodigoPercepcion": "", "PorcentajePercepcion": 0}, "detalle_pagos": []}, "payload_sha1": "4c80eec52fc92d2041d94511e43d81adfd48a182", "payload_length": 1560, "payload_preview": "{\\"empresa\\":{\\"ruc\\":\\"10455923951\\",\\"user\\":\\"MODDATOS\\",\\"pass\\":\\"Moddatos\\",\\"razon_social\\":\\"MSEP PERU SAC\\",\\"nombre_comercial\\":\\"DEMO\\",\\"direccion\\":\\"AV. PRINCIPAL 123\\",\\"urbanizacion\\":\\"ORRANTIA\\",\\"ubigeo\\":\\"150131\\",\\"departamento\\":\\"LIMA\\",\\"provincia\\":\\"LIMA\\",\\"distrito\\":\\"SAN ISIDRO\\",\\"codigolocal\\":\\"\\",\\"telefono_fijo\\":\\"+51 1 2345678\\",\\"correo\\":\\"info@empresademo.com\\",\\"envio_pse\\":\\"\\"},\\"cliente\\":{\\"ruc\\":\\"10455923951\\",\\"tipo_documento\\":\\"6\\",\\"razon_social\\":\\"FERNANDEZ DE LA CRUZ\\"},\\"cabecera\\":{\\"tipo_operacion\\":\\"0101\\",\\"tipo_documento\\":\\"01\\",\\"serie\\":\\"F001\\",\\"numero\\":\\"11\\",\\"fecha_emision\\":\\"2026-04-04 11:07:31\\",\\"tipo_moneda\\":\\"PEN\\",\\"gravadas\\":127.12,\\"inafectas\\":0,\\"exoneradas\\":0,\\"igv\\":22.88,\\"icbper\\":0,\\"impuestos\\":22.88,\\"importe_venta\\":150,\\"descuentoGlobal\\":0,\\"observaciones\\":\\"\\",\\"cod_motivo\\":\\"\\",\\"des_motivo\\":\\"\\"},\\"Pago\\":{\\"FormaPago\\":\\"Contado\\",\\"Monto\\":150},\\"detalle_pagos\\":[],\\"Detraccion\\":{\\"Estado\\":\\"off\\",\\"CodigoDetraccion\\":\\"\\",\\"CodigoMedioPago\\":\\"001\\",\\"NumeroCuentaDetraccion\\":\\"\\",\\"PorcentajeDetraccion\\":0,\\"TotalDetraccion\\":0},\\"Retencion\\":{\\"Estado\\":\\"off\\",\\"CodigoRetencion\\":\\"\\",\\"PorcentajeRetencion\\":0,\\"TotalRetencion\\":0},\\"Percepcion\\":{\\"Estado\\":\\"off\\",\\"CodigoPercepcion\\":\\"\\",\\"PorcentajePercepcion\\":0,\\"TotalPercepcion\\":0},\\"adjunto\\":null,\\"detalle\\":[{\\"sunat\\":\\"\\",\\"codigo\\":\\"3\\",\\"unidad\\":\\"NIU\\",\\"cantidad\\":5,\\"descripcion\\":\\"SIN-SKU - PRODUCTO2\\",\\"tipo_igv\\":\\"10\\",\\"base\\":127.12,\\"igv\\":22.88,\\"impuestos\\":22.88,\\"valor_venta\\":127.12,\\"valor_unitario\\":25.424,\\"precio_unitario\\":30,\\"porcentaje_igv\\":18,\\"icbper\\":\\"off\\",\\"valor_icbper\\":0,\\"igv_icbper\\":0,\\"gratuitas\\":0,\\"descuento\\":0}],\\"letra\\":\\"Ciento cincuenta con 00/100 SOL\\"}"}, "sunat_bridge_endpoint": "https://mundosoftperu.com/MUNDOSOFTPERUSUNATBETA/index.php/Sunat/send_xml", "sunat_bridge_response": {"msg": "La Factura numero F001-11, ha sido aceptada", "res": 1, "firma": "{\\"0\\":\\"cSbhWagp20tyfh\\\\/+ZefZeYSkvNU=\\"}"}, "sunat_bridge_http_code": 200, "sunat_bridge_content_type": "application/x-www-form-urlencoded"}	1	1	2026-04-04 11:07:31-05	2026-04-04 11:07:32-05	\N
38	1	1	1	RECEIPT	B001	14	2026-04-04 05:39:09-05	2026-04-03 19:00:00-05	1	1	1	\N	\N	\N	\N	\N	1	25.42	4.58	30.00	30.00	0.00	0.00	ISSUED	\N	\N	{"sunat_status": "SENT", "sunat_retry_at": "2026-04-04 16:04:41", "sunat_bridge_mode": "BETA", "sunat_last_sync_at": "2026-04-04 16:04:43", "sunat_status_label": "Enviado", "sunat_bridge_method": "POST", "sunat_bridge_request": {"form_key": "datosJSON", "payload_json": {"Pago": {"Monto": 30, "FormaPago": "Contado"}, "letra": "Treinta con 00/100 SOL", "adjunto": null, "cliente": {"ruc": "99999999", "razon_social": "CLIENTE DEMO", "tipo_documento": "6"}, "detalle": [{"igv": 4.58, "base": 25.42, "sunat": "", "codigo": "3", "icbper": "off", "unidad": "NIU", "cantidad": 1, "tipo_igv": "10", "descuento": 0, "gratuitas": 0, "impuestos": 4.58, "igv_icbper": 0, "descripcion": "SIN-SKU - PRODUCTO2", "valor_venta": 25.42, "valor_icbper": 0, "porcentaje_igv": 18.02, "valor_unitario": 25.42, "precio_unitario": 30}], "empresa": {"ruc": "10455923951", "pass": "***", "user": "MODDATOS", "correo": "info@empresademo.com", "ubigeo": "150131", "distrito": "SAN ISIDRO", "direccion": "AV. PRINCIPAL 123", "envio_pse": "", "provincia": "LIMA", "codigolocal": "", "departamento": "LIMA", "razon_social": "MSEP PERU SAC", "urbanizacion": "ORRANTIA", "telefono_fijo": "+51 1 2345678", "nombre_comercial": "DEMO"}, "cabecera": {"igv": 4.58, "serie": "B001", "icbper": 0, "numero": "14", "gravadas": 25.42, "impuestos": 4.58, "inafectas": 0, "cod_motivo": "", "des_motivo": "", "exoneradas": 0, "tipo_moneda": "PEN", "fecha_emision": "2026-04-04 10:39:09", "importe_venta": 30, "observaciones": "", "tipo_documento": "03", "tipo_operacion": "0101", "descuentoGlobal": 0}, "Retencion": {"Estado": "off", "TotalRetencion": 0, "CodigoRetencion": "", "PorcentajeRetencion": 0}, "Detraccion": {"Estado": "off", "CodigoMedioPago": "001", "TotalDetraccion": 0, "CodigoDetraccion": "", "PorcentajeDetraccion": 0, "NumeroCuentaDetraccion": ""}, "Percepcion": {"Estado": "off", "TotalPercepcion": 0, "CodigoPercepcion": "", "PorcentajePercepcion": 0}, "detalle_pagos": []}, "payload_sha1": "ec60f458dae452e42eff419a9b135900775d274c", "payload_length": 1533, "payload_preview": "{\\"empresa\\":{\\"ruc\\":\\"10455923951\\",\\"user\\":\\"MODDATOS\\",\\"pass\\":\\"Moddatos\\",\\"razon_social\\":\\"MSEP PERU SAC\\",\\"nombre_comercial\\":\\"DEMO\\",\\"direccion\\":\\"AV. PRINCIPAL 123\\",\\"urbanizacion\\":\\"ORRANTIA\\",\\"ubigeo\\":\\"150131\\",\\"departamento\\":\\"LIMA\\",\\"provincia\\":\\"LIMA\\",\\"distrito\\":\\"SAN ISIDRO\\",\\"codigolocal\\":\\"\\",\\"telefono_fijo\\":\\"+51 1 2345678\\",\\"correo\\":\\"info@empresademo.com\\",\\"envio_pse\\":\\"\\"},\\"cliente\\":{\\"ruc\\":\\"99999999\\",\\"tipo_documento\\":\\"6\\",\\"razon_social\\":\\"CLIENTE DEMO\\"},\\"cabecera\\":{\\"tipo_operacion\\":\\"0101\\",\\"tipo_documento\\":\\"03\\",\\"serie\\":\\"B001\\",\\"numero\\":\\"14\\",\\"fecha_emision\\":\\"2026-04-04 10:39:09\\",\\"tipo_moneda\\":\\"PEN\\",\\"gravadas\\":25.42,\\"inafectas\\":0,\\"exoneradas\\":0,\\"igv\\":4.58,\\"icbper\\":0,\\"impuestos\\":4.58,\\"importe_venta\\":30,\\"descuentoGlobal\\":0,\\"observaciones\\":\\"\\",\\"cod_motivo\\":\\"\\",\\"des_motivo\\":\\"\\"},\\"Pago\\":{\\"FormaPago\\":\\"Contado\\",\\"Monto\\":30},\\"detalle_pagos\\":[],\\"Detraccion\\":{\\"Estado\\":\\"off\\",\\"CodigoDetraccion\\":\\"\\",\\"CodigoMedioPago\\":\\"001\\",\\"NumeroCuentaDetraccion\\":\\"\\",\\"PorcentajeDetraccion\\":0,\\"TotalDetraccion\\":0},\\"Retencion\\":{\\"Estado\\":\\"off\\",\\"CodigoRetencion\\":\\"\\",\\"PorcentajeRetencion\\":0,\\"TotalRetencion\\":0},\\"Percepcion\\":{\\"Estado\\":\\"off\\",\\"CodigoPercepcion\\":\\"\\",\\"PorcentajePercepcion\\":0,\\"TotalPercepcion\\":0},\\"adjunto\\":null,\\"detalle\\":[{\\"sunat\\":\\"\\",\\"codigo\\":\\"3\\",\\"unidad\\":\\"NIU\\",\\"cantidad\\":1,\\"descripcion\\":\\"SIN-SKU - PRODUCTO2\\",\\"tipo_igv\\":\\"10\\",\\"base\\":25.42,\\"igv\\":4.58,\\"impuestos\\":4.58,\\"valor_venta\\":25.42,\\"valor_unitario\\":25.42,\\"precio_unitario\\":30,\\"porcentaje_igv\\":18.02,\\"icbper\\":\\"off\\",\\"valor_icbper\\":0,\\"igv_icbper\\":0,\\"gratuitas\\":0,\\"descuento\\":0}],\\"letra\\":\\"Treinta con 00/100 SOL\\"}"}, "sunat_bridge_endpoint": "https://mundosoftperu.com/MUNDOSOFTPERUSUNATBETA/index.php/Sunat/send_xml", "sunat_bridge_response": {"raw": "SERVIDOR SUNAT NO RESPONDE <br> [code] => 2017<br> [message] => El numero de documento de identidad del receptor debe ser  RUC - Detalle: xxx.xxx.xxx value='ticket: 1775318978376 error: INFO: 2017 (nodo: \\"cac:PartyIdentification/cbc:ID\\" valor: \\"99999999\\")'"}, "sunat_bridge_http_code": 200, "sunat_bridge_content_type": "application/x-www-form-urlencoded"}	1	1	2026-04-04 10:39:09-05	2026-04-04 11:04:43-05	\N
39	1	1	1	RECEIPT	B001	15	2026-04-04 05:59:16-05	2026-04-03 19:00:00-05	1	1	1	\N	\N	\N	\N	\N	1	84.75	15.25	100.00	100.00	0.00	0.00	ISSUED	\N	\N	{"sunat_status": "ACCEPTED", "sunat_retry_at": "2026-04-04 16:06:45", "sunat_bridge_mode": "BETA", "sunat_last_sync_at": "2026-04-04 16:06:46", "sunat_status_label": "Aceptado", "sunat_bridge_method": "POST", "sunat_bridge_request": {"form_key": "datosJSON", "payload_json": {"Pago": {"Monto": 100, "FormaPago": "Contado"}, "letra": "Ciento con 00/100 SOL", "adjunto": null, "cliente": {"ruc": "99999999", "razon_social": "CLIENTE DEMO", "tipo_documento": "1"}, "detalle": [{"igv": 15.25, "base": 84.75, "sunat": "", "codigo": "1", "icbper": "off", "unidad": "NIU", "cantidad": 10, "tipo_igv": "10", "descuento": 0, "gratuitas": 0, "impuestos": 15.25, "igv_icbper": 0, "descripcion": "SIN-SKU - PRODUCTO1", "valor_venta": 84.75, "valor_icbper": 0, "porcentaje_igv": 17.99, "valor_unitario": 8.475, "precio_unitario": 10}], "empresa": {"ruc": "10455923951", "pass": "***", "user": "MODDATOS", "correo": "info@empresademo.com", "ubigeo": "150131", "distrito": "SAN ISIDRO", "direccion": "AV. PRINCIPAL 123", "envio_pse": "", "provincia": "LIMA", "codigolocal": "", "departamento": "LIMA", "razon_social": "MSEP PERU SAC", "urbanizacion": "ORRANTIA", "telefono_fijo": "+51 1 2345678", "nombre_comercial": "DEMO"}, "cabecera": {"igv": 15.25, "serie": "B001", "icbper": 0, "numero": "15", "gravadas": 84.75, "impuestos": 15.25, "inafectas": 0, "cod_motivo": "", "des_motivo": "", "exoneradas": 0, "tipo_moneda": "PEN", "fecha_emision": "2026-04-04 10:59:16", "importe_venta": 100, "observaciones": "", "tipo_documento": "03", "tipo_operacion": "0101", "descuentoGlobal": 0}, "Retencion": {"Estado": "off", "TotalRetencion": 0, "CodigoRetencion": "", "PorcentajeRetencion": 0}, "Detraccion": {"Estado": "off", "CodigoMedioPago": "001", "TotalDetraccion": 0, "CodigoDetraccion": "", "PorcentajeDetraccion": 0, "NumeroCuentaDetraccion": ""}, "Percepcion": {"Estado": "off", "TotalPercepcion": 0, "CodigoPercepcion": "", "PorcentajePercepcion": 0}, "detalle_pagos": []}, "payload_sha1": "2dc51241e3ac830b8e3dfa949fb5bdd3da7fd5e5", "payload_length": 1539, "payload_preview": "{\\"empresa\\":{\\"ruc\\":\\"10455923951\\",\\"user\\":\\"MODDATOS\\",\\"pass\\":\\"Moddatos\\",\\"razon_social\\":\\"MSEP PERU SAC\\",\\"nombre_comercial\\":\\"DEMO\\",\\"direccion\\":\\"AV. PRINCIPAL 123\\",\\"urbanizacion\\":\\"ORRANTIA\\",\\"ubigeo\\":\\"150131\\",\\"departamento\\":\\"LIMA\\",\\"provincia\\":\\"LIMA\\",\\"distrito\\":\\"SAN ISIDRO\\",\\"codigolocal\\":\\"\\",\\"telefono_fijo\\":\\"+51 1 2345678\\",\\"correo\\":\\"info@empresademo.com\\",\\"envio_pse\\":\\"\\"},\\"cliente\\":{\\"ruc\\":\\"99999999\\",\\"tipo_documento\\":\\"1\\",\\"razon_social\\":\\"CLIENTE DEMO\\"},\\"cabecera\\":{\\"tipo_operacion\\":\\"0101\\",\\"tipo_documento\\":\\"03\\",\\"serie\\":\\"B001\\",\\"numero\\":\\"15\\",\\"fecha_emision\\":\\"2026-04-04 10:59:16\\",\\"tipo_moneda\\":\\"PEN\\",\\"gravadas\\":84.75,\\"inafectas\\":0,\\"exoneradas\\":0,\\"igv\\":15.25,\\"icbper\\":0,\\"impuestos\\":15.25,\\"importe_venta\\":100,\\"descuentoGlobal\\":0,\\"observaciones\\":\\"\\",\\"cod_motivo\\":\\"\\",\\"des_motivo\\":\\"\\"},\\"Pago\\":{\\"FormaPago\\":\\"Contado\\",\\"Monto\\":100},\\"detalle_pagos\\":[],\\"Detraccion\\":{\\"Estado\\":\\"off\\",\\"CodigoDetraccion\\":\\"\\",\\"CodigoMedioPago\\":\\"001\\",\\"NumeroCuentaDetraccion\\":\\"\\",\\"PorcentajeDetraccion\\":0,\\"TotalDetraccion\\":0},\\"Retencion\\":{\\"Estado\\":\\"off\\",\\"CodigoRetencion\\":\\"\\",\\"PorcentajeRetencion\\":0,\\"TotalRetencion\\":0},\\"Percepcion\\":{\\"Estado\\":\\"off\\",\\"CodigoPercepcion\\":\\"\\",\\"PorcentajePercepcion\\":0,\\"TotalPercepcion\\":0},\\"adjunto\\":null,\\"detalle\\":[{\\"sunat\\":\\"\\",\\"codigo\\":\\"1\\",\\"unidad\\":\\"NIU\\",\\"cantidad\\":10,\\"descripcion\\":\\"SIN-SKU - PRODUCTO1\\",\\"tipo_igv\\":\\"10\\",\\"base\\":84.75,\\"igv\\":15.25,\\"impuestos\\":15.25,\\"valor_venta\\":84.75,\\"valor_unitario\\":8.475,\\"precio_unitario\\":10,\\"porcentaje_igv\\":17.99,\\"icbper\\":\\"off\\",\\"valor_icbper\\":0,\\"igv_icbper\\":0,\\"gratuitas\\":0,\\"descuento\\":0}],\\"letra\\":\\"Ciento con 00/100 SOL\\"}"}, "sunat_bridge_endpoint": "https://mundosoftperu.com/MUNDOSOFTPERUSUNATBETA/index.php/Sunat/send_xml", "sunat_bridge_response": {"msg": "La Boleta numero B001-15, ha sido aceptada", "res": 1, "firma": "{\\"0\\":\\"+SnQ6+nq7pU2wGnOBc3kMqHaRi0=\\"}"}, "sunat_bridge_http_code": 200, "sunat_bridge_content_type": "application/x-www-form-urlencoded"}	1	1	2026-04-04 10:59:16-05	2026-04-04 11:06:46-05	\N
\.


--
-- Data for Name: customer_price_profiles; Type: TABLE DATA; Schema: sales; Owner: postgres
--

COPY sales.customer_price_profiles (id, company_id, customer_id, default_tier_id, discount_percent, status) FROM stdin;
1	1	1	\N	0.0000	1
2	1	2	\N	0.0000	1
\.


--
-- Data for Name: customer_types; Type: TABLE DATA; Schema: sales; Owner: postgres
--

COPY sales.customer_types (id, name, sunat_code, sunat_abbr, is_active, created_at, updated_at) FROM stdin;
1	Persona Natural	1	DOC.NACIONAL DE IDEN	t	2026-04-04 10:36:55.332349-05	2026-04-04 10:36:55.332349-05
2	Persona Jurídica	6	REG. UNICO DE CONTRI	t	2026-04-04 10:36:55.332349-05	2026-04-04 10:36:55.332349-05
3	Empresas Del Extranjero	0	DOC.TRIB.NO.DOM.SIN	t	2026-04-04 10:36:55.332349-05	2026-04-04 10:36:55.332349-05
4	Carnet de Extranjeria	4	CARNET DE EXTRANJERIA	t	2026-04-04 10:36:55.332349-05	2026-04-04 10:36:55.332349-05
5	Pasaporte	7	PASAPORTE	t	2026-04-04 10:36:55.332349-05	2026-04-04 10:36:55.332349-05
6	Otros	8	OTROS	t	2026-04-04 10:36:55.332349-05	2026-04-04 10:36:55.332349-05
\.


--
-- Data for Name: customers; Type: TABLE DATA; Schema: sales; Owner: postgres
--

COPY sales.customers (id, company_id, doc_type, doc_number, legal_name, trade_name, first_name, last_name, email, phone, address, plate, status, created_at, updated_at, deleted_at, customer_type_id) FROM stdin;
1	1	1	99999999	CLIENTE DEMO	\N	CLIENTE	DEMO	cliente.demo@local.test	900000000	DIRECCION DEMO	\N	1	2026-03-10 22:11:30.41965-05	2026-03-10 22:11:30.41965-05	\N	1
2	1	6	10455923951	FERNANDEZ DE LA CRUZ	FERNANDEZ DE LA CRUZ	FERNANDEZ	DE LA CRUZ	\N	\N	LIMA	RRR4	1	2026-03-11 16:42:07.100998-05	2026-03-11 16:42:07.100998-05	\N	2
\.


--
-- Data for Name: document_sequences; Type: TABLE DATA; Schema: sales; Owner: postgres
--

COPY sales.document_sequences (id, company_id, branch_id, warehouse_id, document_kind, series, current_number, status) FROM stdin;
\.


--
-- Data for Name: order_sequences; Type: TABLE DATA; Schema: sales; Owner: postgres
--

COPY sales.order_sequences (id, company_id, branch_id, doc_type, series, current_number) FROM stdin;
\.


--
-- Data for Name: price_tiers; Type: TABLE DATA; Schema: sales; Owner: postgres
--

COPY sales.price_tiers (id, company_id, code, name, min_qty, max_qty, priority, status) FROM stdin;
\.


--
-- Data for Name: product_price_tier_values; Type: TABLE DATA; Schema: sales; Owner: postgres
--

COPY sales.product_price_tier_values (id, company_id, product_id, price_tier_id, unit_id, unit_price, status, updated_by, updated_at) FROM stdin;
\.


--
-- Data for Name: product_tier_prices; Type: TABLE DATA; Schema: sales; Owner: postgres
--

COPY sales.product_tier_prices (id, company_id, product_id, tier_id, currency_id, unit_price, valid_from, valid_to, status) FROM stdin;
\.


--
-- Data for Name: sales_order_item_lots; Type: TABLE DATA; Schema: sales; Owner: postgres
--

COPY sales.sales_order_item_lots (id, sales_order_item_id, lot_id, qty, created_at) FROM stdin;
\.


--
-- Data for Name: sales_order_items; Type: TABLE DATA; Schema: sales; Owner: postgres
--

COPY sales.sales_order_items (id, order_id, line_no, product_id, unit_id, description, qty, unit_price, unit_cost, discount_total, tax_total, subtotal, total) FROM stdin;
\.


--
-- Data for Name: sales_order_payments; Type: TABLE DATA; Schema: sales; Owner: postgres
--

COPY sales.sales_order_payments (id, order_id, payment_method_id, amount, due_at, notes, created_at) FROM stdin;
\.


--
-- Data for Name: sales_orders; Type: TABLE DATA; Schema: sales; Owner: postgres
--

COPY sales.sales_orders (id, company_id, branch_id, warehouse_id, customer_id, currency_id, payment_method_id, seller_user_id, sequence_series, sequence_number, issue_at, exchange_rate, subtotal, tax_total, total, change_amount, notes, status, discount_stock, show_image, created_by, updated_by, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: series_numbers; Type: TABLE DATA; Schema: sales; Owner: postgres
--

COPY sales.series_numbers (id, company_id, branch_id, warehouse_id, document_kind, series, current_number, number_padding, reset_policy, is_enabled, valid_from, valid_to, updated_by, updated_at) FROM stdin;
1	1	1	\N	INVOICE	F001	1	8	NONE	t	2026-03-10	\N	1	2026-03-11 04:09:35-05
5	1	1	1	SALES_ORDER	P001	3	8	NONE	t	\N	\N	2	2026-03-20 17:17:19-05
4	1	1	1	QUOTATION	C001	8	8	NONE	t	\N	\N	2	2026-03-26 04:50:34-05
3	1	1	1	RECEIPT	B001	15	8	NONE	t	\N	\N	1	2026-04-04 10:59:16-05
2	1	1	1	INVOICE	F001	11	8	NONE	t	\N	\N	1	2026-04-04 11:07:31-05
\.


--
-- Data for Name: wholesale_settings; Type: TABLE DATA; Schema: sales; Owner: postgres
--

COPY sales.wholesale_settings (company_id, is_enabled, pricing_mode, allow_customer_override, updated_at) FROM stdin;
\.


--
-- Name: modules_id_seq; Type: SEQUENCE SET; Schema: appcfg; Owner: postgres
--

SELECT pg_catalog.setval('appcfg.modules_id_seq', 3, true);


--
-- Name: saved_filters_id_seq; Type: SEQUENCE SET; Schema: appcfg; Owner: postgres
--

SELECT pg_catalog.setval('appcfg.saved_filters_id_seq', 1, false);


--
-- Name: ui_entities_id_seq; Type: SEQUENCE SET; Schema: appcfg; Owner: postgres
--

SELECT pg_catalog.setval('appcfg.ui_entities_id_seq', 1, false);


--
-- Name: ui_fields_id_seq; Type: SEQUENCE SET; Schema: appcfg; Owner: postgres
--

SELECT pg_catalog.setval('appcfg.ui_fields_id_seq', 1, false);


--
-- Name: permissions_id_seq; Type: SEQUENCE SET; Schema: auth; Owner: postgres
--

SELECT pg_catalog.setval('auth.permissions_id_seq', 1, false);


--
-- Name: refresh_tokens_id_seq; Type: SEQUENCE SET; Schema: auth; Owner: postgres
--

SELECT pg_catalog.setval('auth.refresh_tokens_id_seq', 886, true);


--
-- Name: roles_id_seq; Type: SEQUENCE SET; Schema: auth; Owner: postgres
--

SELECT pg_catalog.setval('auth.roles_id_seq', 3, true);


--
-- Name: users_id_seq; Type: SEQUENCE SET; Schema: auth; Owner: postgres
--

SELECT pg_catalog.setval('auth.users_id_seq', 3, true);


--
-- Name: documents_id_seq; Type: SEQUENCE SET; Schema: billing; Owner: postgres
--

SELECT pg_catalog.setval('billing.documents_id_seq', 1, false);


--
-- Name: branches_id_seq; Type: SEQUENCE SET; Schema: core; Owner: postgres
--

SELECT pg_catalog.setval('core.branches_id_seq', 3, true);


--
-- Name: companies_id_seq; Type: SEQUENCE SET; Schema: core; Owner: postgres
--

SELECT pg_catalog.setval('core.companies_id_seq', 3, true);


--
-- Name: company_igv_rates_id_seq; Type: SEQUENCE SET; Schema: core; Owner: postgres
--

SELECT pg_catalog.setval('core.company_igv_rates_id_seq', 1, true);


--
-- Name: currencies_id_seq; Type: SEQUENCE SET; Schema: core; Owner: postgres
--

SELECT pg_catalog.setval('core.currencies_id_seq', 2, true);


--
-- Name: payment_methods_id_seq; Type: SEQUENCE SET; Schema: core; Owner: postgres
--

SELECT pg_catalog.setval('core.payment_methods_id_seq', 3, true);


--
-- Name: units_id_seq; Type: SEQUENCE SET; Schema: core; Owner: postgres
--

SELECT pg_catalog.setval('core.units_id_seq', 63, true);


--
-- Name: categories_id_seq; Type: SEQUENCE SET; Schema: inventory; Owner: postgres
--

SELECT pg_catalog.setval('inventory.categories_id_seq', 1, true);


--
-- Name: inventory_ledger_id_seq; Type: SEQUENCE SET; Schema: inventory; Owner: postgres
--

SELECT pg_catalog.setval('inventory.inventory_ledger_id_seq', 25, true);


--
-- Name: outbox_events_id_seq; Type: SEQUENCE SET; Schema: inventory; Owner: postgres
--

SELECT pg_catalog.setval('inventory.outbox_events_id_seq', 1, false);


--
-- Name: product_brands_id_seq; Type: SEQUENCE SET; Schema: inventory; Owner: postgres
--

SELECT pg_catalog.setval('inventory.product_brands_id_seq', 2, true);


--
-- Name: product_lines_id_seq; Type: SEQUENCE SET; Schema: inventory; Owner: postgres
--

SELECT pg_catalog.setval('inventory.product_lines_id_seq', 1, true);


--
-- Name: product_locations_id_seq; Type: SEQUENCE SET; Schema: inventory; Owner: postgres
--

SELECT pg_catalog.setval('inventory.product_locations_id_seq', 1, true);


--
-- Name: product_lots_id_seq; Type: SEQUENCE SET; Schema: inventory; Owner: postgres
--

SELECT pg_catalog.setval('inventory.product_lots_id_seq', 1, true);


--
-- Name: product_recipe_items_id_seq; Type: SEQUENCE SET; Schema: inventory; Owner: postgres
--

SELECT pg_catalog.setval('inventory.product_recipe_items_id_seq', 1, false);


--
-- Name: product_recipes_id_seq; Type: SEQUENCE SET; Schema: inventory; Owner: postgres
--

SELECT pg_catalog.setval('inventory.product_recipes_id_seq', 1, false);


--
-- Name: product_uom_conversions_id_seq; Type: SEQUENCE SET; Schema: inventory; Owner: postgres
--

SELECT pg_catalog.setval('inventory.product_uom_conversions_id_seq', 1, false);


--
-- Name: product_warranties_id_seq; Type: SEQUENCE SET; Schema: inventory; Owner: postgres
--

SELECT pg_catalog.setval('inventory.product_warranties_id_seq', 1, true);


--
-- Name: products_id_seq; Type: SEQUENCE SET; Schema: inventory; Owner: postgres
--

SELECT pg_catalog.setval('inventory.products_id_seq', 3, true);


--
-- Name: report_requests_id_seq; Type: SEQUENCE SET; Schema: inventory; Owner: postgres
--

SELECT pg_catalog.setval('inventory.report_requests_id_seq', 1, true);


--
-- Name: stock_entries_id_seq; Type: SEQUENCE SET; Schema: inventory; Owner: postgres
--

SELECT pg_catalog.setval('inventory.stock_entries_id_seq', 5, true);


--
-- Name: stock_entry_items_id_seq; Type: SEQUENCE SET; Schema: inventory; Owner: postgres
--

SELECT pg_catalog.setval('inventory.stock_entry_items_id_seq', 3, true);


--
-- Name: stock_transformation_lines_id_seq; Type: SEQUENCE SET; Schema: inventory; Owner: postgres
--

SELECT pg_catalog.setval('inventory.stock_transformation_lines_id_seq', 1, false);


--
-- Name: stock_transformations_id_seq; Type: SEQUENCE SET; Schema: inventory; Owner: postgres
--

SELECT pg_catalog.setval('inventory.stock_transformations_id_seq', 1, false);


--
-- Name: warehouses_id_seq; Type: SEQUENCE SET; Schema: inventory; Owner: postgres
--

SELECT pg_catalog.setval('inventory.warehouses_id_seq', 2, true);


--
-- Name: detraccion_service_codes_id_seq; Type: SEQUENCE SET; Schema: master; Owner: postgres
--

SELECT pg_catalog.setval('master.detraccion_service_codes_id_seq', 31, true);


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.failed_jobs_id_seq', 1, false);


--
-- Name: migrations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.migrations_id_seq', 10, true);


--
-- Name: users_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.users_id_seq', 1, false);


--
-- Name: cash_movements_id_seq; Type: SEQUENCE SET; Schema: sales; Owner: postgres
--

SELECT pg_catalog.setval('sales.cash_movements_id_seq', 11, true);


--
-- Name: cash_registers_id_seq; Type: SEQUENCE SET; Schema: sales; Owner: postgres
--

SELECT pg_catalog.setval('sales.cash_registers_id_seq', 1, true);


--
-- Name: cash_sessions_id_seq; Type: SEQUENCE SET; Schema: sales; Owner: postgres
--

SELECT pg_catalog.setval('sales.cash_sessions_id_seq', 8, true);


--
-- Name: commercial_document_item_lots_id_seq; Type: SEQUENCE SET; Schema: sales; Owner: postgres
--

SELECT pg_catalog.setval('sales.commercial_document_item_lots_id_seq', 5, true);


--
-- Name: commercial_document_items_id_seq; Type: SEQUENCE SET; Schema: sales; Owner: postgres
--

SELECT pg_catalog.setval('sales.commercial_document_items_id_seq', 37, true);


--
-- Name: commercial_document_payments_id_seq; Type: SEQUENCE SET; Schema: sales; Owner: postgres
--

SELECT pg_catalog.setval('sales.commercial_document_payments_id_seq', 24, true);


--
-- Name: commercial_documents_id_seq; Type: SEQUENCE SET; Schema: sales; Owner: postgres
--

SELECT pg_catalog.setval('sales.commercial_documents_id_seq', 40, true);


--
-- Name: customer_price_profiles_id_seq; Type: SEQUENCE SET; Schema: sales; Owner: postgres
--

SELECT pg_catalog.setval('sales.customer_price_profiles_id_seq', 2, true);


--
-- Name: customer_types_id_seq; Type: SEQUENCE SET; Schema: sales; Owner: postgres
--

SELECT pg_catalog.setval('sales.customer_types_id_seq', 12, true);


--
-- Name: customers_id_seq; Type: SEQUENCE SET; Schema: sales; Owner: postgres
--

SELECT pg_catalog.setval('sales.customers_id_seq', 2, true);


--
-- Name: document_sequences_id_seq; Type: SEQUENCE SET; Schema: sales; Owner: postgres
--

SELECT pg_catalog.setval('sales.document_sequences_id_seq', 1, false);


--
-- Name: order_sequences_id_seq; Type: SEQUENCE SET; Schema: sales; Owner: postgres
--

SELECT pg_catalog.setval('sales.order_sequences_id_seq', 1, false);


--
-- Name: price_tiers_id_seq; Type: SEQUENCE SET; Schema: sales; Owner: postgres
--

SELECT pg_catalog.setval('sales.price_tiers_id_seq', 1, false);


--
-- Name: product_price_tier_values_id_seq; Type: SEQUENCE SET; Schema: sales; Owner: postgres
--

SELECT pg_catalog.setval('sales.product_price_tier_values_id_seq', 1, false);


--
-- Name: product_tier_prices_id_seq; Type: SEQUENCE SET; Schema: sales; Owner: postgres
--

SELECT pg_catalog.setval('sales.product_tier_prices_id_seq', 1, false);


--
-- Name: sales_order_item_lots_id_seq; Type: SEQUENCE SET; Schema: sales; Owner: postgres
--

SELECT pg_catalog.setval('sales.sales_order_item_lots_id_seq', 1, false);


--
-- Name: sales_order_items_id_seq; Type: SEQUENCE SET; Schema: sales; Owner: postgres
--

SELECT pg_catalog.setval('sales.sales_order_items_id_seq', 1, false);


--
-- Name: sales_order_payments_id_seq; Type: SEQUENCE SET; Schema: sales; Owner: postgres
--

SELECT pg_catalog.setval('sales.sales_order_payments_id_seq', 1, false);


--
-- Name: sales_orders_id_seq; Type: SEQUENCE SET; Schema: sales; Owner: postgres
--

SELECT pg_catalog.setval('sales.sales_orders_id_seq', 1, false);


--
-- Name: series_numbers_id_seq; Type: SEQUENCE SET; Schema: sales; Owner: postgres
--

SELECT pg_catalog.setval('sales.series_numbers_id_seq', 5, true);


--
-- Name: branch_feature_toggles branch_feature_toggles_pkey; Type: CONSTRAINT; Schema: appcfg; Owner: postgres
--

ALTER TABLE ONLY appcfg.branch_feature_toggles
    ADD CONSTRAINT branch_feature_toggles_pkey PRIMARY KEY (company_id, branch_id, feature_code);


--
-- Name: branch_modules branch_modules_pkey; Type: CONSTRAINT; Schema: appcfg; Owner: postgres
--

ALTER TABLE ONLY appcfg.branch_modules
    ADD CONSTRAINT branch_modules_pkey PRIMARY KEY (company_id, branch_id, module_id);


--
-- Name: company_feature_toggles company_feature_toggles_pkey; Type: CONSTRAINT; Schema: appcfg; Owner: postgres
--

ALTER TABLE ONLY appcfg.company_feature_toggles
    ADD CONSTRAINT company_feature_toggles_pkey PRIMARY KEY (company_id, feature_code);


--
-- Name: company_modules company_modules_pkey; Type: CONSTRAINT; Schema: appcfg; Owner: postgres
--

ALTER TABLE ONLY appcfg.company_modules
    ADD CONSTRAINT company_modules_pkey PRIMARY KEY (company_id, module_id);


--
-- Name: company_role_profiles company_role_profiles_pkey; Type: CONSTRAINT; Schema: appcfg; Owner: postgres
--

ALTER TABLE ONLY appcfg.company_role_profiles
    ADD CONSTRAINT company_role_profiles_pkey PRIMARY KEY (company_id, role_id);


--
-- Name: company_ui_field_settings company_ui_field_settings_pkey; Type: CONSTRAINT; Schema: appcfg; Owner: postgres
--

ALTER TABLE ONLY appcfg.company_ui_field_settings
    ADD CONSTRAINT company_ui_field_settings_pkey PRIMARY KEY (company_id, field_id);


--
-- Name: company_units company_units_pkey; Type: CONSTRAINT; Schema: appcfg; Owner: postgres
--

ALTER TABLE ONLY appcfg.company_units
    ADD CONSTRAINT company_units_pkey PRIMARY KEY (company_id, unit_id);


--
-- Name: modules modules_code_key; Type: CONSTRAINT; Schema: appcfg; Owner: postgres
--

ALTER TABLE ONLY appcfg.modules
    ADD CONSTRAINT modules_code_key UNIQUE (code);


--
-- Name: modules modules_pkey; Type: CONSTRAINT; Schema: appcfg; Owner: postgres
--

ALTER TABLE ONLY appcfg.modules
    ADD CONSTRAINT modules_pkey PRIMARY KEY (id);


--
-- Name: saved_filters saved_filters_pkey; Type: CONSTRAINT; Schema: appcfg; Owner: postgres
--

ALTER TABLE ONLY appcfg.saved_filters
    ADD CONSTRAINT saved_filters_pkey PRIMARY KEY (id);


--
-- Name: ui_entities ui_entities_module_id_code_key; Type: CONSTRAINT; Schema: appcfg; Owner: postgres
--

ALTER TABLE ONLY appcfg.ui_entities
    ADD CONSTRAINT ui_entities_module_id_code_key UNIQUE (module_id, code);


--
-- Name: ui_entities ui_entities_pkey; Type: CONSTRAINT; Schema: appcfg; Owner: postgres
--

ALTER TABLE ONLY appcfg.ui_entities
    ADD CONSTRAINT ui_entities_pkey PRIMARY KEY (id);


--
-- Name: ui_fields ui_fields_entity_id_code_key; Type: CONSTRAINT; Schema: appcfg; Owner: postgres
--

ALTER TABLE ONLY appcfg.ui_fields
    ADD CONSTRAINT ui_fields_entity_id_code_key UNIQUE (entity_id, code);


--
-- Name: ui_fields ui_fields_pkey; Type: CONSTRAINT; Schema: appcfg; Owner: postgres
--

ALTER TABLE ONLY appcfg.ui_fields
    ADD CONSTRAINT ui_fields_pkey PRIMARY KEY (id);


--
-- Name: permissions permissions_code_key; Type: CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.permissions
    ADD CONSTRAINT permissions_code_key UNIQUE (code);


--
-- Name: permissions permissions_pkey; Type: CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.permissions
    ADD CONSTRAINT permissions_pkey PRIMARY KEY (id);


--
-- Name: refresh_tokens refresh_tokens_pkey; Type: CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.refresh_tokens
    ADD CONSTRAINT refresh_tokens_pkey PRIMARY KEY (id);


--
-- Name: role_module_access role_module_access_pkey; Type: CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.role_module_access
    ADD CONSTRAINT role_module_access_pkey PRIMARY KEY (role_id, module_id);


--
-- Name: role_permissions role_permissions_pkey; Type: CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.role_permissions
    ADD CONSTRAINT role_permissions_pkey PRIMARY KEY (role_id, permission_id);


--
-- Name: role_ui_field_access role_ui_field_access_pkey; Type: CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.role_ui_field_access
    ADD CONSTRAINT role_ui_field_access_pkey PRIMARY KEY (role_id, field_id);


--
-- Name: roles roles_company_id_code_key; Type: CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.roles
    ADD CONSTRAINT roles_company_id_code_key UNIQUE (company_id, code);


--
-- Name: roles roles_pkey; Type: CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.roles
    ADD CONSTRAINT roles_pkey PRIMARY KEY (id);


--
-- Name: user_module_overrides user_module_overrides_pkey; Type: CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.user_module_overrides
    ADD CONSTRAINT user_module_overrides_pkey PRIMARY KEY (user_id, module_id);


--
-- Name: user_roles user_roles_pkey; Type: CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.user_roles
    ADD CONSTRAINT user_roles_pkey PRIMARY KEY (user_id, role_id);


--
-- Name: user_ui_field_access user_ui_field_access_pkey; Type: CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.user_ui_field_access
    ADD CONSTRAINT user_ui_field_access_pkey PRIMARY KEY (user_id, field_id);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: users users_username_key; Type: CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.users
    ADD CONSTRAINT users_username_key UNIQUE (username);


--
-- Name: documents documents_company_id_doc_type_series_number_key; Type: CONSTRAINT; Schema: billing; Owner: postgres
--

ALTER TABLE ONLY billing.documents
    ADD CONSTRAINT documents_company_id_doc_type_series_number_key UNIQUE (company_id, doc_type, series, number);


--
-- Name: documents documents_pkey; Type: CONSTRAINT; Schema: billing; Owner: postgres
--

ALTER TABLE ONLY billing.documents
    ADD CONSTRAINT documents_pkey PRIMARY KEY (id);


--
-- Name: branches branches_company_id_code_key; Type: CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.branches
    ADD CONSTRAINT branches_company_id_code_key UNIQUE (company_id, code);


--
-- Name: branches branches_pkey; Type: CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.branches
    ADD CONSTRAINT branches_pkey PRIMARY KEY (id);


--
-- Name: companies companies_pkey; Type: CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.companies
    ADD CONSTRAINT companies_pkey PRIMARY KEY (id);


--
-- Name: companies companies_tax_id_key; Type: CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.companies
    ADD CONSTRAINT companies_tax_id_key UNIQUE (tax_id);


--
-- Name: company_igv_rates company_igv_rates_pkey; Type: CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.company_igv_rates
    ADD CONSTRAINT company_igv_rates_pkey PRIMARY KEY (id);


--
-- Name: company_settings company_settings_pkey; Type: CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.company_settings
    ADD CONSTRAINT company_settings_pkey PRIMARY KEY (company_id);


--
-- Name: currencies currencies_code_key; Type: CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.currencies
    ADD CONSTRAINT currencies_code_key UNIQUE (code);


--
-- Name: currencies currencies_pkey; Type: CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.currencies
    ADD CONSTRAINT currencies_pkey PRIMARY KEY (id);


--
-- Name: payment_methods payment_methods_code_key; Type: CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.payment_methods
    ADD CONSTRAINT payment_methods_code_key UNIQUE (code);


--
-- Name: payment_methods payment_methods_pkey; Type: CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.payment_methods
    ADD CONSTRAINT payment_methods_pkey PRIMARY KEY (id);


--
-- Name: tax_categories tax_categories_pkey; Type: CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.tax_categories
    ADD CONSTRAINT tax_categories_pkey PRIMARY KEY (id);


--
-- Name: units units_code_key; Type: CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.units
    ADD CONSTRAINT units_code_key UNIQUE (code);


--
-- Name: units units_pkey; Type: CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.units
    ADD CONSTRAINT units_pkey PRIMARY KEY (id);


--
-- Name: categories categories_company_id_name_key; Type: CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.categories
    ADD CONSTRAINT categories_company_id_name_key UNIQUE (company_id, name);


--
-- Name: categories categories_pkey; Type: CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.categories
    ADD CONSTRAINT categories_pkey PRIMARY KEY (id);


--
-- Name: inventory_ledger inventory_ledger_pkey; Type: CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.inventory_ledger
    ADD CONSTRAINT inventory_ledger_pkey PRIMARY KEY (id);


--
-- Name: inventory_settings inventory_settings_pkey; Type: CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.inventory_settings
    ADD CONSTRAINT inventory_settings_pkey PRIMARY KEY (company_id);


--
-- Name: lot_expiry_projection lot_expiry_projection_pkey; Type: CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.lot_expiry_projection
    ADD CONSTRAINT lot_expiry_projection_pkey PRIMARY KEY (company_id, warehouse_id, product_id, lot_id);


--
-- Name: outbox_events outbox_events_pkey; Type: CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.outbox_events
    ADD CONSTRAINT outbox_events_pkey PRIMARY KEY (id);


--
-- Name: product_brands product_brands_company_id_name_key; Type: CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.product_brands
    ADD CONSTRAINT product_brands_company_id_name_key UNIQUE (company_id, name);


--
-- Name: product_brands product_brands_pkey; Type: CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.product_brands
    ADD CONSTRAINT product_brands_pkey PRIMARY KEY (id);


--
-- Name: product_lines product_lines_company_id_name_key; Type: CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.product_lines
    ADD CONSTRAINT product_lines_company_id_name_key UNIQUE (company_id, name);


--
-- Name: product_lines product_lines_pkey; Type: CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.product_lines
    ADD CONSTRAINT product_lines_pkey PRIMARY KEY (id);


--
-- Name: product_locations product_locations_company_id_name_key; Type: CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.product_locations
    ADD CONSTRAINT product_locations_company_id_name_key UNIQUE (company_id, name);


--
-- Name: product_locations product_locations_pkey; Type: CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.product_locations
    ADD CONSTRAINT product_locations_pkey PRIMARY KEY (id);


--
-- Name: product_lots product_lots_company_id_warehouse_id_product_id_lot_code_key; Type: CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.product_lots
    ADD CONSTRAINT product_lots_company_id_warehouse_id_product_id_lot_code_key UNIQUE (company_id, warehouse_id, product_id, lot_code);


--
-- Name: product_lots product_lots_pkey; Type: CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.product_lots
    ADD CONSTRAINT product_lots_pkey PRIMARY KEY (id);


--
-- Name: product_recipe_items product_recipe_items_pkey; Type: CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.product_recipe_items
    ADD CONSTRAINT product_recipe_items_pkey PRIMARY KEY (id);


--
-- Name: product_recipe_items product_recipe_items_recipe_id_component_product_id_key; Type: CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.product_recipe_items
    ADD CONSTRAINT product_recipe_items_recipe_id_component_product_id_key UNIQUE (recipe_id, component_product_id);


--
-- Name: product_recipes product_recipes_company_id_code_key; Type: CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.product_recipes
    ADD CONSTRAINT product_recipes_company_id_code_key UNIQUE (company_id, code);


--
-- Name: product_recipes product_recipes_pkey; Type: CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.product_recipes
    ADD CONSTRAINT product_recipes_pkey PRIMARY KEY (id);


--
-- Name: product_sale_units product_sale_units_pkey; Type: CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.product_sale_units
    ADD CONSTRAINT product_sale_units_pkey PRIMARY KEY (company_id, product_id, unit_id);


--
-- Name: product_uom_conversions product_uom_conversions_company_id_product_id_from_unit_id__key; Type: CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.product_uom_conversions
    ADD CONSTRAINT product_uom_conversions_company_id_product_id_from_unit_id__key UNIQUE (company_id, product_id, from_unit_id, to_unit_id);


--
-- Name: product_uom_conversions product_uom_conversions_pkey; Type: CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.product_uom_conversions
    ADD CONSTRAINT product_uom_conversions_pkey PRIMARY KEY (id);


--
-- Name: product_warranties product_warranties_company_id_name_key; Type: CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.product_warranties
    ADD CONSTRAINT product_warranties_company_id_name_key UNIQUE (company_id, name);


--
-- Name: product_warranties product_warranties_pkey; Type: CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.product_warranties
    ADD CONSTRAINT product_warranties_pkey PRIMARY KEY (id);


--
-- Name: products products_company_id_sku_key; Type: CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.products
    ADD CONSTRAINT products_company_id_sku_key UNIQUE (company_id, sku);


--
-- Name: products products_pkey; Type: CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.products
    ADD CONSTRAINT products_pkey PRIMARY KEY (id);


--
-- Name: report_requests report_requests_pkey; Type: CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.report_requests
    ADD CONSTRAINT report_requests_pkey PRIMARY KEY (id);


--
-- Name: stock_daily_snapshot stock_daily_snapshot_pkey; Type: CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.stock_daily_snapshot
    ADD CONSTRAINT stock_daily_snapshot_pkey PRIMARY KEY (snapshot_date, company_id, warehouse_id, product_id, lot_id);


--
-- Name: stock_entries stock_entries_pkey; Type: CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.stock_entries
    ADD CONSTRAINT stock_entries_pkey PRIMARY KEY (id);


--
-- Name: stock_entry_items stock_entry_items_pkey; Type: CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.stock_entry_items
    ADD CONSTRAINT stock_entry_items_pkey PRIMARY KEY (id);


--
-- Name: stock_transformation_lines stock_transformation_lines_pkey; Type: CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.stock_transformation_lines
    ADD CONSTRAINT stock_transformation_lines_pkey PRIMARY KEY (id);


--
-- Name: stock_transformations stock_transformations_company_id_transformation_code_key; Type: CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.stock_transformations
    ADD CONSTRAINT stock_transformations_company_id_transformation_code_key UNIQUE (company_id, transformation_code);


--
-- Name: stock_transformations stock_transformations_pkey; Type: CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.stock_transformations
    ADD CONSTRAINT stock_transformations_pkey PRIMARY KEY (id);


--
-- Name: transformation_settings transformation_settings_pkey; Type: CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.transformation_settings
    ADD CONSTRAINT transformation_settings_pkey PRIMARY KEY (company_id);


--
-- Name: warehouses warehouses_company_id_code_key; Type: CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.warehouses
    ADD CONSTRAINT warehouses_company_id_code_key UNIQUE (company_id, code);


--
-- Name: warehouses warehouses_pkey; Type: CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.warehouses
    ADD CONSTRAINT warehouses_pkey PRIMARY KEY (id);


--
-- Name: additional_legends additional_legends_code_key; Type: CONSTRAINT; Schema: master; Owner: postgres
--

ALTER TABLE ONLY master.additional_legends
    ADD CONSTRAINT additional_legends_code_key UNIQUE (code);


--
-- Name: additional_legends additional_legends_pkey; Type: CONSTRAINT; Schema: master; Owner: postgres
--

ALTER TABLE ONLY master.additional_legends
    ADD CONSTRAINT additional_legends_pkey PRIMARY KEY (id);


--
-- Name: credit_note_reasons credit_note_reasons_code_key; Type: CONSTRAINT; Schema: master; Owner: postgres
--

ALTER TABLE ONLY master.credit_note_reasons
    ADD CONSTRAINT credit_note_reasons_code_key UNIQUE (code);


--
-- Name: credit_note_reasons credit_note_reasons_pkey; Type: CONSTRAINT; Schema: master; Owner: postgres
--

ALTER TABLE ONLY master.credit_note_reasons
    ADD CONSTRAINT credit_note_reasons_pkey PRIMARY KEY (id);


--
-- Name: debit_note_reasons debit_note_reasons_code_key; Type: CONSTRAINT; Schema: master; Owner: postgres
--

ALTER TABLE ONLY master.debit_note_reasons
    ADD CONSTRAINT debit_note_reasons_code_key UNIQUE (code);


--
-- Name: debit_note_reasons debit_note_reasons_pkey; Type: CONSTRAINT; Schema: master; Owner: postgres
--

ALTER TABLE ONLY master.debit_note_reasons
    ADD CONSTRAINT debit_note_reasons_pkey PRIMARY KEY (id);


--
-- Name: detraccion_service_codes detraccion_service_codes_code_unique; Type: CONSTRAINT; Schema: master; Owner: postgres
--

ALTER TABLE ONLY master.detraccion_service_codes
    ADD CONSTRAINT detraccion_service_codes_code_unique UNIQUE (code);


--
-- Name: detraccion_service_codes detraccion_service_codes_pkey; Type: CONSTRAINT; Schema: master; Owner: postgres
--

ALTER TABLE ONLY master.detraccion_service_codes
    ADD CONSTRAINT detraccion_service_codes_pkey PRIMARY KEY (id);


--
-- Name: employee_roles employee_roles_name_key; Type: CONSTRAINT; Schema: master; Owner: postgres
--

ALTER TABLE ONLY master.employee_roles
    ADD CONSTRAINT employee_roles_name_key UNIQUE (name);


--
-- Name: employee_roles employee_roles_pkey; Type: CONSTRAINT; Schema: master; Owner: postgres
--

ALTER TABLE ONLY master.employee_roles
    ADD CONSTRAINT employee_roles_pkey PRIMARY KEY (id);


--
-- Name: geo_ubigeo geo_ubigeo_code_key; Type: CONSTRAINT; Schema: master; Owner: postgres
--

ALTER TABLE ONLY master.geo_ubigeo
    ADD CONSTRAINT geo_ubigeo_code_key UNIQUE (code);


--
-- Name: geo_ubigeo geo_ubigeo_pkey; Type: CONSTRAINT; Schema: master; Owner: postgres
--

ALTER TABLE ONLY master.geo_ubigeo
    ADD CONSTRAINT geo_ubigeo_pkey PRIMARY KEY (id);


--
-- Name: item_types item_types_name_key; Type: CONSTRAINT; Schema: master; Owner: postgres
--

ALTER TABLE ONLY master.item_types
    ADD CONSTRAINT item_types_name_key UNIQUE (name);


--
-- Name: item_types item_types_pkey; Type: CONSTRAINT; Schema: master; Owner: postgres
--

ALTER TABLE ONLY master.item_types
    ADD CONSTRAINT item_types_pkey PRIMARY KEY (id);


--
-- Name: payment_types payment_types_name_key; Type: CONSTRAINT; Schema: master; Owner: postgres
--

ALTER TABLE ONLY master.payment_types
    ADD CONSTRAINT payment_types_name_key UNIQUE (name);


--
-- Name: payment_types payment_types_pkey; Type: CONSTRAINT; Schema: master; Owner: postgres
--

ALTER TABLE ONLY master.payment_types
    ADD CONSTRAINT payment_types_pkey PRIMARY KEY (id);


--
-- Name: shipment_transfer_reasons shipment_transfer_reasons_code_key; Type: CONSTRAINT; Schema: master; Owner: postgres
--

ALTER TABLE ONLY master.shipment_transfer_reasons
    ADD CONSTRAINT shipment_transfer_reasons_code_key UNIQUE (code);


--
-- Name: shipment_transfer_reasons shipment_transfer_reasons_pkey; Type: CONSTRAINT; Schema: master; Owner: postgres
--

ALTER TABLE ONLY master.shipment_transfer_reasons
    ADD CONSTRAINT shipment_transfer_reasons_pkey PRIMARY KEY (id);


--
-- Name: shipment_transport_modes shipment_transport_modes_code_key; Type: CONSTRAINT; Schema: master; Owner: postgres
--

ALTER TABLE ONLY master.shipment_transport_modes
    ADD CONSTRAINT shipment_transport_modes_code_key UNIQUE (code);


--
-- Name: shipment_transport_modes shipment_transport_modes_pkey; Type: CONSTRAINT; Schema: master; Owner: postgres
--

ALTER TABLE ONLY master.shipment_transport_modes
    ADD CONSTRAINT shipment_transport_modes_pkey PRIMARY KEY (id);


--
-- Name: sunat_uom sunat_uom_code_key; Type: CONSTRAINT; Schema: master; Owner: postgres
--

ALTER TABLE ONLY master.sunat_uom
    ADD CONSTRAINT sunat_uom_code_key UNIQUE (code);


--
-- Name: sunat_uom sunat_uom_pkey; Type: CONSTRAINT; Schema: master; Owner: postgres
--

ALTER TABLE ONLY master.sunat_uom
    ADD CONSTRAINT sunat_uom_pkey PRIMARY KEY (id);


--
-- Name: tax_codes tax_codes_pkey; Type: CONSTRAINT; Schema: master; Owner: postgres
--

ALTER TABLE ONLY master.tax_codes
    ADD CONSTRAINT tax_codes_pkey PRIMARY KEY (code);


--
-- Name: vat_categories vat_categories_code_key; Type: CONSTRAINT; Schema: master; Owner: postgres
--

ALTER TABLE ONLY master.vat_categories
    ADD CONSTRAINT vat_categories_code_key UNIQUE (code);


--
-- Name: vat_categories vat_categories_pkey; Type: CONSTRAINT; Schema: master; Owner: postgres
--

ALTER TABLE ONLY master.vat_categories
    ADD CONSTRAINT vat_categories_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: users users_email_unique; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_unique UNIQUE (email);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: cash_movements cash_movements_pkey; Type: CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.cash_movements
    ADD CONSTRAINT cash_movements_pkey PRIMARY KEY (id);


--
-- Name: cash_registers cash_registers_company_id_code_key; Type: CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.cash_registers
    ADD CONSTRAINT cash_registers_company_id_code_key UNIQUE (company_id, code);


--
-- Name: cash_registers cash_registers_pkey; Type: CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.cash_registers
    ADD CONSTRAINT cash_registers_pkey PRIMARY KEY (id);


--
-- Name: cash_sessions cash_sessions_pkey; Type: CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.cash_sessions
    ADD CONSTRAINT cash_sessions_pkey PRIMARY KEY (id);


--
-- Name: commercial_document_item_lots commercial_document_item_lots_pkey; Type: CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.commercial_document_item_lots
    ADD CONSTRAINT commercial_document_item_lots_pkey PRIMARY KEY (id);


--
-- Name: commercial_document_items commercial_document_items_document_id_line_no_key; Type: CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.commercial_document_items
    ADD CONSTRAINT commercial_document_items_document_id_line_no_key UNIQUE (document_id, line_no);


--
-- Name: commercial_document_items commercial_document_items_pkey; Type: CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.commercial_document_items
    ADD CONSTRAINT commercial_document_items_pkey PRIMARY KEY (id);


--
-- Name: commercial_document_payments commercial_document_payments_pkey; Type: CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.commercial_document_payments
    ADD CONSTRAINT commercial_document_payments_pkey PRIMARY KEY (id);


--
-- Name: commercial_documents commercial_documents_company_id_document_kind_series_number_key; Type: CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.commercial_documents
    ADD CONSTRAINT commercial_documents_company_id_document_kind_series_number_key UNIQUE (company_id, document_kind, series, number);


--
-- Name: commercial_documents commercial_documents_pkey; Type: CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.commercial_documents
    ADD CONSTRAINT commercial_documents_pkey PRIMARY KEY (id);


--
-- Name: customer_price_profiles customer_price_profiles_company_id_customer_id_key; Type: CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.customer_price_profiles
    ADD CONSTRAINT customer_price_profiles_company_id_customer_id_key UNIQUE (company_id, customer_id);


--
-- Name: customer_price_profiles customer_price_profiles_pkey; Type: CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.customer_price_profiles
    ADD CONSTRAINT customer_price_profiles_pkey PRIMARY KEY (id);


--
-- Name: customer_types customer_types_pkey; Type: CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.customer_types
    ADD CONSTRAINT customer_types_pkey PRIMARY KEY (id);


--
-- Name: customer_types customer_types_sunat_code_unique; Type: CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.customer_types
    ADD CONSTRAINT customer_types_sunat_code_unique UNIQUE (sunat_code);


--
-- Name: customers customers_pkey; Type: CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.customers
    ADD CONSTRAINT customers_pkey PRIMARY KEY (id);


--
-- Name: document_sequences document_sequences_company_id_branch_id_warehouse_id_docume_key; Type: CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.document_sequences
    ADD CONSTRAINT document_sequences_company_id_branch_id_warehouse_id_docume_key UNIQUE (company_id, branch_id, warehouse_id, document_kind, series);


--
-- Name: document_sequences document_sequences_pkey; Type: CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.document_sequences
    ADD CONSTRAINT document_sequences_pkey PRIMARY KEY (id);


--
-- Name: order_sequences order_sequences_company_id_branch_id_doc_type_series_key; Type: CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.order_sequences
    ADD CONSTRAINT order_sequences_company_id_branch_id_doc_type_series_key UNIQUE (company_id, branch_id, doc_type, series);


--
-- Name: order_sequences order_sequences_pkey; Type: CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.order_sequences
    ADD CONSTRAINT order_sequences_pkey PRIMARY KEY (id);


--
-- Name: price_tiers price_tiers_company_id_code_key; Type: CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.price_tiers
    ADD CONSTRAINT price_tiers_company_id_code_key UNIQUE (company_id, code);


--
-- Name: price_tiers price_tiers_pkey; Type: CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.price_tiers
    ADD CONSTRAINT price_tiers_pkey PRIMARY KEY (id);


--
-- Name: product_price_tier_values product_price_tier_values_company_id_product_id_price_tier__key; Type: CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.product_price_tier_values
    ADD CONSTRAINT product_price_tier_values_company_id_product_id_price_tier__key UNIQUE (company_id, product_id, price_tier_id, unit_id);


--
-- Name: product_price_tier_values product_price_tier_values_pkey; Type: CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.product_price_tier_values
    ADD CONSTRAINT product_price_tier_values_pkey PRIMARY KEY (id);


--
-- Name: product_tier_prices product_tier_prices_company_id_product_id_tier_id_currency__key; Type: CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.product_tier_prices
    ADD CONSTRAINT product_tier_prices_company_id_product_id_tier_id_currency__key UNIQUE (company_id, product_id, tier_id, currency_id, valid_from);


--
-- Name: product_tier_prices product_tier_prices_pkey; Type: CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.product_tier_prices
    ADD CONSTRAINT product_tier_prices_pkey PRIMARY KEY (id);


--
-- Name: sales_order_item_lots sales_order_item_lots_pkey; Type: CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.sales_order_item_lots
    ADD CONSTRAINT sales_order_item_lots_pkey PRIMARY KEY (id);


--
-- Name: sales_order_items sales_order_items_order_id_line_no_key; Type: CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.sales_order_items
    ADD CONSTRAINT sales_order_items_order_id_line_no_key UNIQUE (order_id, line_no);


--
-- Name: sales_order_items sales_order_items_pkey; Type: CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.sales_order_items
    ADD CONSTRAINT sales_order_items_pkey PRIMARY KEY (id);


--
-- Name: sales_order_payments sales_order_payments_pkey; Type: CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.sales_order_payments
    ADD CONSTRAINT sales_order_payments_pkey PRIMARY KEY (id);


--
-- Name: sales_orders sales_orders_company_id_sequence_series_sequence_number_key; Type: CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.sales_orders
    ADD CONSTRAINT sales_orders_company_id_sequence_series_sequence_number_key UNIQUE (company_id, sequence_series, sequence_number);


--
-- Name: sales_orders sales_orders_pkey; Type: CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.sales_orders
    ADD CONSTRAINT sales_orders_pkey PRIMARY KEY (id);


--
-- Name: series_numbers series_numbers_company_id_branch_id_warehouse_id_document_k_key; Type: CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.series_numbers
    ADD CONSTRAINT series_numbers_company_id_branch_id_warehouse_id_document_k_key UNIQUE (company_id, branch_id, warehouse_id, document_kind, series);


--
-- Name: series_numbers series_numbers_pkey; Type: CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.series_numbers
    ADD CONSTRAINT series_numbers_pkey PRIMARY KEY (id);


--
-- Name: wholesale_settings wholesale_settings_pkey; Type: CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.wholesale_settings
    ADD CONSTRAINT wholesale_settings_pkey PRIMARY KEY (company_id);


--
-- Name: idx_branch_feature_enabled; Type: INDEX; Schema: appcfg; Owner: postgres
--

CREATE INDEX idx_branch_feature_enabled ON appcfg.branch_feature_toggles USING btree (company_id, branch_id, is_enabled);


--
-- Name: idx_branch_modules_enabled; Type: INDEX; Schema: appcfg; Owner: postgres
--

CREATE INDEX idx_branch_modules_enabled ON appcfg.branch_modules USING btree (company_id, branch_id, is_enabled);


--
-- Name: idx_company_feature_enabled; Type: INDEX; Schema: appcfg; Owner: postgres
--

CREATE INDEX idx_company_feature_enabled ON appcfg.company_feature_toggles USING btree (company_id, is_enabled);


--
-- Name: idx_company_modules_enabled; Type: INDEX; Schema: appcfg; Owner: postgres
--

CREATE INDEX idx_company_modules_enabled ON appcfg.company_modules USING btree (company_id, is_enabled);


--
-- Name: idx_saved_filters_company_user; Type: INDEX; Schema: appcfg; Owner: postgres
--

CREATE INDEX idx_saved_filters_company_user ON appcfg.saved_filters USING btree (company_id, user_id);


--
-- Name: idx_saved_filters_entity; Type: INDEX; Schema: appcfg; Owner: postgres
--

CREATE INDEX idx_saved_filters_entity ON appcfg.saved_filters USING btree (entity_id, status);


--
-- Name: idx_ui_fields_entity; Type: INDEX; Schema: appcfg; Owner: postgres
--

CREATE INDEX idx_ui_fields_entity ON appcfg.ui_fields USING btree (entity_id, status);


--
-- Name: idx_refresh_tokens_user; Type: INDEX; Schema: auth; Owner: postgres
--

CREATE INDEX idx_refresh_tokens_user ON auth.refresh_tokens USING btree (user_id);


--
-- Name: idx_role_module_access_module; Type: INDEX; Schema: auth; Owner: postgres
--

CREATE INDEX idx_role_module_access_module ON auth.role_module_access USING btree (module_id);


--
-- Name: idx_user_module_overrides_module; Type: INDEX; Schema: auth; Owner: postgres
--

CREATE INDEX idx_user_module_overrides_module ON auth.user_module_overrides USING btree (module_id);


--
-- Name: company_igv_rates_active_unique_idx; Type: INDEX; Schema: core; Owner: postgres
--

CREATE UNIQUE INDEX company_igv_rates_active_unique_idx ON core.company_igv_rates USING btree (company_id) WHERE (is_active = true);


--
-- Name: company_igv_rates_company_idx; Type: INDEX; Schema: core; Owner: postgres
--

CREATE INDEX company_igv_rates_company_idx ON core.company_igv_rates USING btree (company_id);


--
-- Name: idx_inv_ledger_product_date; Type: INDEX; Schema: inventory; Owner: postgres
--

CREATE INDEX idx_inv_ledger_product_date ON inventory.inventory_ledger USING btree (company_id, product_id, moved_at DESC);


--
-- Name: idx_inv_ledger_ref; Type: INDEX; Schema: inventory; Owner: postgres
--

CREATE INDEX idx_inv_ledger_ref ON inventory.inventory_ledger USING btree (ref_type, ref_id);


--
-- Name: idx_inv_ledger_warehouse_date; Type: INDEX; Schema: inventory; Owner: postgres
--

CREATE INDEX idx_inv_ledger_warehouse_date ON inventory.inventory_ledger USING btree (company_id, warehouse_id, moved_at DESC);


--
-- Name: idx_inventory_lot_expiry_company_expiry; Type: INDEX; Schema: inventory; Owner: postgres
--

CREATE INDEX idx_inventory_lot_expiry_company_expiry ON inventory.lot_expiry_projection USING btree (company_id, expires_at);


--
-- Name: idx_inventory_lot_expiry_company_product; Type: INDEX; Schema: inventory; Owner: postgres
--

CREATE INDEX idx_inventory_lot_expiry_company_product ON inventory.lot_expiry_projection USING btree (company_id, product_id);


--
-- Name: idx_inventory_lot_expiry_company_wh; Type: INDEX; Schema: inventory; Owner: postgres
--

CREATE INDEX idx_inventory_lot_expiry_company_wh ON inventory.lot_expiry_projection USING btree (company_id, warehouse_id);


--
-- Name: idx_inventory_outbox_company_created; Type: INDEX; Schema: inventory; Owner: postgres
--

CREATE INDEX idx_inventory_outbox_company_created ON inventory.outbox_events USING btree (company_id, created_at DESC);


--
-- Name: idx_inventory_outbox_event_type; Type: INDEX; Schema: inventory; Owner: postgres
--

CREATE INDEX idx_inventory_outbox_event_type ON inventory.outbox_events USING btree (event_type);


--
-- Name: idx_inventory_outbox_status_available; Type: INDEX; Schema: inventory; Owner: postgres
--

CREATE INDEX idx_inventory_outbox_status_available ON inventory.outbox_events USING btree (status, available_at);


--
-- Name: idx_inventory_report_requests_company_status; Type: INDEX; Schema: inventory; Owner: postgres
--

CREATE INDEX idx_inventory_report_requests_company_status ON inventory.report_requests USING btree (company_id, status);


--
-- Name: idx_inventory_report_requests_company_type; Type: INDEX; Schema: inventory; Owner: postgres
--

CREATE INDEX idx_inventory_report_requests_company_type ON inventory.report_requests USING btree (company_id, report_type);


--
-- Name: idx_inventory_report_requests_requested_at; Type: INDEX; Schema: inventory; Owner: postgres
--

CREATE INDEX idx_inventory_report_requests_requested_at ON inventory.report_requests USING btree (requested_at DESC);


--
-- Name: idx_inventory_stock_daily_company_date; Type: INDEX; Schema: inventory; Owner: postgres
--

CREATE INDEX idx_inventory_stock_daily_company_date ON inventory.stock_daily_snapshot USING btree (company_id, snapshot_date DESC);


--
-- Name: idx_inventory_stock_daily_company_product; Type: INDEX; Schema: inventory; Owner: postgres
--

CREATE INDEX idx_inventory_stock_daily_company_product ON inventory.stock_daily_snapshot USING btree (company_id, product_id, snapshot_date DESC);


--
-- Name: idx_inventory_stock_daily_company_wh; Type: INDEX; Schema: inventory; Owner: postgres
--

CREATE INDEX idx_inventory_stock_daily_company_wh ON inventory.stock_daily_snapshot USING btree (company_id, warehouse_id, snapshot_date DESC);


--
-- Name: idx_ledger_lot_date; Type: INDEX; Schema: inventory; Owner: postgres
--

CREATE INDEX idx_ledger_lot_date ON inventory.inventory_ledger USING btree (lot_id, moved_at);


--
-- Name: idx_ledger_product_wh_date; Type: INDEX; Schema: inventory; Owner: postgres
--

CREATE INDEX idx_ledger_product_wh_date ON inventory.inventory_ledger USING btree (product_id, warehouse_id, moved_at);


--
-- Name: idx_product_lots_company_code; Type: INDEX; Schema: inventory; Owner: postgres
--

CREATE INDEX idx_product_lots_company_code ON inventory.product_lots USING btree (company_id, lot_code);


--
-- Name: idx_product_lots_prod_wh_exp; Type: INDEX; Schema: inventory; Owner: postgres
--

CREATE INDEX idx_product_lots_prod_wh_exp ON inventory.product_lots USING btree (product_id, warehouse_id, expires_at);


--
-- Name: idx_product_recipes_output; Type: INDEX; Schema: inventory; Owner: postgres
--

CREATE INDEX idx_product_recipes_output ON inventory.product_recipes USING btree (output_product_id, status);


--
-- Name: idx_product_uom_conv_product; Type: INDEX; Schema: inventory; Owner: postgres
--

CREATE INDEX idx_product_uom_conv_product ON inventory.product_uom_conversions USING btree (product_id, status);


--
-- Name: idx_products_company_name; Type: INDEX; Schema: inventory; Owner: postgres
--

CREATE INDEX idx_products_company_name ON inventory.products USING btree (company_id, name);


--
-- Name: idx_recipe_items_recipe; Type: INDEX; Schema: inventory; Owner: postgres
--

CREATE INDEX idx_recipe_items_recipe ON inventory.product_recipe_items USING btree (recipe_id);


--
-- Name: idx_stock_transform_company_date; Type: INDEX; Schema: inventory; Owner: postgres
--

CREATE INDEX idx_stock_transform_company_date ON inventory.stock_transformations USING btree (company_id, executed_at);


--
-- Name: idx_stock_transform_lines_transform; Type: INDEX; Schema: inventory; Owner: postgres
--

CREATE INDEX idx_stock_transform_lines_transform ON inventory.stock_transformation_lines USING btree (transformation_id, line_type);


--
-- Name: stock_entries_company_issue_idx; Type: INDEX; Schema: inventory; Owner: postgres
--

CREATE INDEX stock_entries_company_issue_idx ON inventory.stock_entries USING btree (company_id, issue_at DESC, id DESC);


--
-- Name: stock_entry_items_entry_idx; Type: INDEX; Schema: inventory; Owner: postgres
--

CREATE INDEX stock_entry_items_entry_idx ON inventory.stock_entry_items USING btree (entry_id);


--
-- Name: idx_master_geo_full_name; Type: INDEX; Schema: master; Owner: postgres
--

CREATE INDEX idx_master_geo_full_name ON master.geo_ubigeo USING btree (full_name);


--
-- Name: idx_master_geo_status; Type: INDEX; Schema: master; Owner: postgres
--

CREATE INDEX idx_master_geo_status ON master.geo_ubigeo USING btree (status);


--
-- Name: idx_master_vat_tax_code; Type: INDEX; Schema: master; Owner: postgres
--

CREATE INDEX idx_master_vat_tax_code ON master.vat_categories USING btree (tax_code);


--
-- Name: password_resets_email_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX password_resets_email_index ON public.password_resets USING btree (email);


--
-- Name: customers_customer_type_id_idx; Type: INDEX; Schema: sales; Owner: postgres
--

CREATE INDEX customers_customer_type_id_idx ON sales.customers USING btree (customer_type_id);


--
-- Name: idx_cash_movements_company; Type: INDEX; Schema: sales; Owner: postgres
--

CREATE INDEX idx_cash_movements_company ON sales.cash_movements USING btree (company_id, movement_at DESC);


--
-- Name: idx_cash_movements_register; Type: INDEX; Schema: sales; Owner: postgres
--

CREATE INDEX idx_cash_movements_register ON sales.cash_movements USING btree (cash_register_id, movement_at DESC);


--
-- Name: idx_cash_movements_session; Type: INDEX; Schema: sales; Owner: postgres
--

CREATE INDEX idx_cash_movements_session ON sales.cash_movements USING btree (cash_session_id);


--
-- Name: idx_cash_movements_session_type; Type: INDEX; Schema: sales; Owner: postgres
--

CREATE INDEX idx_cash_movements_session_type ON sales.cash_movements USING btree (cash_session_id, movement_type);


--
-- Name: idx_cash_sessions_company; Type: INDEX; Schema: sales; Owner: postgres
--

CREATE INDEX idx_cash_sessions_company ON sales.cash_sessions USING btree (company_id, status);


--
-- Name: idx_cash_sessions_company_date; Type: INDEX; Schema: sales; Owner: postgres
--

CREATE INDEX idx_cash_sessions_company_date ON sales.cash_sessions USING btree (company_id, opened_at);


--
-- Name: idx_cash_sessions_register; Type: INDEX; Schema: sales; Owner: postgres
--

CREATE INDEX idx_cash_sessions_register ON sales.cash_sessions USING btree (cash_register_id, status);


--
-- Name: idx_cash_sessions_register_status; Type: INDEX; Schema: sales; Owner: postgres
--

CREATE INDEX idx_cash_sessions_register_status ON sales.cash_sessions USING btree (cash_register_id, status);


--
-- Name: idx_commercial_doc_items_doc; Type: INDEX; Schema: sales; Owner: postgres
--

CREATE INDEX idx_commercial_doc_items_doc ON sales.commercial_document_items USING btree (document_id);


--
-- Name: idx_commercial_doc_items_product; Type: INDEX; Schema: sales; Owner: postgres
--

CREATE INDEX idx_commercial_doc_items_product ON sales.commercial_document_items USING btree (product_id);


--
-- Name: idx_commercial_doc_items_tier; Type: INDEX; Schema: sales; Owner: postgres
--

CREATE INDEX idx_commercial_doc_items_tier ON sales.commercial_document_items USING btree (price_tier_id);


--
-- Name: idx_commercial_docs_company_issue; Type: INDEX; Schema: sales; Owner: postgres
--

CREATE INDEX idx_commercial_docs_company_issue ON sales.commercial_documents USING btree (company_id, issue_at);


--
-- Name: idx_commercial_docs_company_kind_status; Type: INDEX; Schema: sales; Owner: postgres
--

CREATE INDEX idx_commercial_docs_company_kind_status ON sales.commercial_documents USING btree (company_id, document_kind, status);


--
-- Name: idx_commercial_docs_customer; Type: INDEX; Schema: sales; Owner: postgres
--

CREATE INDEX idx_commercial_docs_customer ON sales.commercial_documents USING btree (company_id, customer_id);


--
-- Name: idx_commercial_docs_reference; Type: INDEX; Schema: sales; Owner: postgres
--

CREATE INDEX idx_commercial_docs_reference ON sales.commercial_documents USING btree (reference_document_id);


--
-- Name: idx_commercial_item_lots_item; Type: INDEX; Schema: sales; Owner: postgres
--

CREATE INDEX idx_commercial_item_lots_item ON sales.commercial_document_item_lots USING btree (document_item_id);


--
-- Name: idx_commercial_payments_doc; Type: INDEX; Schema: sales; Owner: postgres
--

CREATE INDEX idx_commercial_payments_doc ON sales.commercial_document_payments USING btree (document_id, status);


--
-- Name: idx_customer_price_profiles_customer; Type: INDEX; Schema: sales; Owner: postgres
--

CREATE INDEX idx_customer_price_profiles_customer ON sales.customer_price_profiles USING btree (customer_id, status);


--
-- Name: idx_customers_doc; Type: INDEX; Schema: sales; Owner: postgres
--

CREATE INDEX idx_customers_doc ON sales.customers USING btree (company_id, doc_number);


--
-- Name: idx_doc_sequences_company_kind; Type: INDEX; Schema: sales; Owner: postgres
--

CREATE INDEX idx_doc_sequences_company_kind ON sales.document_sequences USING btree (company_id, document_kind, status);


--
-- Name: idx_product_tier_prices_product; Type: INDEX; Schema: sales; Owner: postgres
--

CREATE INDEX idx_product_tier_prices_product ON sales.product_tier_prices USING btree (product_id, tier_id, status);


--
-- Name: idx_sales_item_lots_item; Type: INDEX; Schema: sales; Owner: postgres
--

CREATE INDEX idx_sales_item_lots_item ON sales.sales_order_item_lots USING btree (sales_order_item_id);


--
-- Name: idx_sales_item_lots_lot; Type: INDEX; Schema: sales; Owner: postgres
--

CREATE INDEX idx_sales_item_lots_lot ON sales.sales_order_item_lots USING btree (lot_id);


--
-- Name: idx_sales_orders_customer; Type: INDEX; Schema: sales; Owner: postgres
--

CREATE INDEX idx_sales_orders_customer ON sales.sales_orders USING btree (company_id, customer_id);


--
-- Name: idx_sales_orders_issue; Type: INDEX; Schema: sales; Owner: postgres
--

CREATE INDEX idx_sales_orders_issue ON sales.sales_orders USING btree (company_id, issue_at);


--
-- Name: idx_sales_orders_status; Type: INDEX; Schema: sales; Owner: postgres
--

CREATE INDEX idx_sales_orders_status ON sales.sales_orders USING btree (company_id, status);


--
-- Name: idx_series_numbers_company_kind; Type: INDEX; Schema: sales; Owner: postgres
--

CREATE INDEX idx_series_numbers_company_kind ON sales.series_numbers USING btree (company_id, document_kind, is_enabled);


--
-- Name: branch_feature_toggles branch_feature_toggles_branch_id_fkey; Type: FK CONSTRAINT; Schema: appcfg; Owner: postgres
--

ALTER TABLE ONLY appcfg.branch_feature_toggles
    ADD CONSTRAINT branch_feature_toggles_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES core.branches(id) ON DELETE CASCADE;


--
-- Name: branch_feature_toggles branch_feature_toggles_company_id_fkey; Type: FK CONSTRAINT; Schema: appcfg; Owner: postgres
--

ALTER TABLE ONLY appcfg.branch_feature_toggles
    ADD CONSTRAINT branch_feature_toggles_company_id_fkey FOREIGN KEY (company_id) REFERENCES core.companies(id) ON DELETE CASCADE;


--
-- Name: branch_modules branch_modules_branch_id_fkey; Type: FK CONSTRAINT; Schema: appcfg; Owner: postgres
--

ALTER TABLE ONLY appcfg.branch_modules
    ADD CONSTRAINT branch_modules_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES core.branches(id) ON DELETE CASCADE;


--
-- Name: branch_modules branch_modules_company_id_fkey; Type: FK CONSTRAINT; Schema: appcfg; Owner: postgres
--

ALTER TABLE ONLY appcfg.branch_modules
    ADD CONSTRAINT branch_modules_company_id_fkey FOREIGN KEY (company_id) REFERENCES core.companies(id) ON DELETE CASCADE;


--
-- Name: branch_modules branch_modules_module_id_fkey; Type: FK CONSTRAINT; Schema: appcfg; Owner: postgres
--

ALTER TABLE ONLY appcfg.branch_modules
    ADD CONSTRAINT branch_modules_module_id_fkey FOREIGN KEY (module_id) REFERENCES appcfg.modules(id) ON DELETE CASCADE;


--
-- Name: company_feature_toggles company_feature_toggles_company_id_fkey; Type: FK CONSTRAINT; Schema: appcfg; Owner: postgres
--

ALTER TABLE ONLY appcfg.company_feature_toggles
    ADD CONSTRAINT company_feature_toggles_company_id_fkey FOREIGN KEY (company_id) REFERENCES core.companies(id) ON DELETE CASCADE;


--
-- Name: company_modules company_modules_company_id_fkey; Type: FK CONSTRAINT; Schema: appcfg; Owner: postgres
--

ALTER TABLE ONLY appcfg.company_modules
    ADD CONSTRAINT company_modules_company_id_fkey FOREIGN KEY (company_id) REFERENCES core.companies(id) ON DELETE CASCADE;


--
-- Name: company_modules company_modules_module_id_fkey; Type: FK CONSTRAINT; Schema: appcfg; Owner: postgres
--

ALTER TABLE ONLY appcfg.company_modules
    ADD CONSTRAINT company_modules_module_id_fkey FOREIGN KEY (module_id) REFERENCES appcfg.modules(id) ON DELETE CASCADE;


--
-- Name: company_ui_field_settings company_ui_field_settings_company_id_fkey; Type: FK CONSTRAINT; Schema: appcfg; Owner: postgres
--

ALTER TABLE ONLY appcfg.company_ui_field_settings
    ADD CONSTRAINT company_ui_field_settings_company_id_fkey FOREIGN KEY (company_id) REFERENCES core.companies(id) ON DELETE CASCADE;


--
-- Name: company_ui_field_settings company_ui_field_settings_field_id_fkey; Type: FK CONSTRAINT; Schema: appcfg; Owner: postgres
--

ALTER TABLE ONLY appcfg.company_ui_field_settings
    ADD CONSTRAINT company_ui_field_settings_field_id_fkey FOREIGN KEY (field_id) REFERENCES appcfg.ui_fields(id) ON DELETE CASCADE;


--
-- Name: saved_filters saved_filters_company_id_fkey; Type: FK CONSTRAINT; Schema: appcfg; Owner: postgres
--

ALTER TABLE ONLY appcfg.saved_filters
    ADD CONSTRAINT saved_filters_company_id_fkey FOREIGN KEY (company_id) REFERENCES core.companies(id) ON DELETE CASCADE;


--
-- Name: saved_filters saved_filters_entity_id_fkey; Type: FK CONSTRAINT; Schema: appcfg; Owner: postgres
--

ALTER TABLE ONLY appcfg.saved_filters
    ADD CONSTRAINT saved_filters_entity_id_fkey FOREIGN KEY (entity_id) REFERENCES appcfg.ui_entities(id) ON DELETE CASCADE;


--
-- Name: ui_entities ui_entities_module_id_fkey; Type: FK CONSTRAINT; Schema: appcfg; Owner: postgres
--

ALTER TABLE ONLY appcfg.ui_entities
    ADD CONSTRAINT ui_entities_module_id_fkey FOREIGN KEY (module_id) REFERENCES appcfg.modules(id) ON DELETE CASCADE;


--
-- Name: ui_fields ui_fields_entity_id_fkey; Type: FK CONSTRAINT; Schema: appcfg; Owner: postgres
--

ALTER TABLE ONLY appcfg.ui_fields
    ADD CONSTRAINT ui_fields_entity_id_fkey FOREIGN KEY (entity_id) REFERENCES appcfg.ui_entities(id) ON DELETE CASCADE;


--
-- Name: refresh_tokens refresh_tokens_user_id_fkey; Type: FK CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.refresh_tokens
    ADD CONSTRAINT refresh_tokens_user_id_fkey FOREIGN KEY (user_id) REFERENCES auth.users(id) ON DELETE CASCADE;


--
-- Name: role_module_access role_module_access_module_id_fkey; Type: FK CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.role_module_access
    ADD CONSTRAINT role_module_access_module_id_fkey FOREIGN KEY (module_id) REFERENCES appcfg.modules(id) ON DELETE CASCADE;


--
-- Name: role_module_access role_module_access_role_id_fkey; Type: FK CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.role_module_access
    ADD CONSTRAINT role_module_access_role_id_fkey FOREIGN KEY (role_id) REFERENCES auth.roles(id) ON DELETE CASCADE;


--
-- Name: role_permissions role_permissions_permission_id_fkey; Type: FK CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.role_permissions
    ADD CONSTRAINT role_permissions_permission_id_fkey FOREIGN KEY (permission_id) REFERENCES auth.permissions(id) ON DELETE CASCADE;


--
-- Name: role_permissions role_permissions_role_id_fkey; Type: FK CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.role_permissions
    ADD CONSTRAINT role_permissions_role_id_fkey FOREIGN KEY (role_id) REFERENCES auth.roles(id) ON DELETE CASCADE;


--
-- Name: role_ui_field_access role_ui_field_access_field_id_fkey; Type: FK CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.role_ui_field_access
    ADD CONSTRAINT role_ui_field_access_field_id_fkey FOREIGN KEY (field_id) REFERENCES appcfg.ui_fields(id) ON DELETE CASCADE;


--
-- Name: role_ui_field_access role_ui_field_access_role_id_fkey; Type: FK CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.role_ui_field_access
    ADD CONSTRAINT role_ui_field_access_role_id_fkey FOREIGN KEY (role_id) REFERENCES auth.roles(id) ON DELETE CASCADE;


--
-- Name: roles roles_company_id_fkey; Type: FK CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.roles
    ADD CONSTRAINT roles_company_id_fkey FOREIGN KEY (company_id) REFERENCES core.companies(id);


--
-- Name: user_module_overrides user_module_overrides_module_id_fkey; Type: FK CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.user_module_overrides
    ADD CONSTRAINT user_module_overrides_module_id_fkey FOREIGN KEY (module_id) REFERENCES appcfg.modules(id) ON DELETE CASCADE;


--
-- Name: user_module_overrides user_module_overrides_user_id_fkey; Type: FK CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.user_module_overrides
    ADD CONSTRAINT user_module_overrides_user_id_fkey FOREIGN KEY (user_id) REFERENCES auth.users(id) ON DELETE CASCADE;


--
-- Name: user_roles user_roles_role_id_fkey; Type: FK CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.user_roles
    ADD CONSTRAINT user_roles_role_id_fkey FOREIGN KEY (role_id) REFERENCES auth.roles(id) ON DELETE CASCADE;


--
-- Name: user_roles user_roles_user_id_fkey; Type: FK CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.user_roles
    ADD CONSTRAINT user_roles_user_id_fkey FOREIGN KEY (user_id) REFERENCES auth.users(id) ON DELETE CASCADE;


--
-- Name: user_ui_field_access user_ui_field_access_field_id_fkey; Type: FK CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.user_ui_field_access
    ADD CONSTRAINT user_ui_field_access_field_id_fkey FOREIGN KEY (field_id) REFERENCES appcfg.ui_fields(id) ON DELETE CASCADE;


--
-- Name: user_ui_field_access user_ui_field_access_user_id_fkey; Type: FK CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.user_ui_field_access
    ADD CONSTRAINT user_ui_field_access_user_id_fkey FOREIGN KEY (user_id) REFERENCES auth.users(id) ON DELETE CASCADE;


--
-- Name: users users_branch_id_fkey; Type: FK CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.users
    ADD CONSTRAINT users_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES core.branches(id);


--
-- Name: users users_company_id_fkey; Type: FK CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.users
    ADD CONSTRAINT users_company_id_fkey FOREIGN KEY (company_id) REFERENCES core.companies(id);


--
-- Name: documents documents_company_id_fkey; Type: FK CONSTRAINT; Schema: billing; Owner: postgres
--

ALTER TABLE ONLY billing.documents
    ADD CONSTRAINT documents_company_id_fkey FOREIGN KEY (company_id) REFERENCES core.companies(id);


--
-- Name: documents documents_currency_id_fkey; Type: FK CONSTRAINT; Schema: billing; Owner: postgres
--

ALTER TABLE ONLY billing.documents
    ADD CONSTRAINT documents_currency_id_fkey FOREIGN KEY (currency_id) REFERENCES core.currencies(id);


--
-- Name: documents documents_customer_id_fkey; Type: FK CONSTRAINT; Schema: billing; Owner: postgres
--

ALTER TABLE ONLY billing.documents
    ADD CONSTRAINT documents_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES sales.customers(id);


--
-- Name: documents documents_source_order_id_fkey; Type: FK CONSTRAINT; Schema: billing; Owner: postgres
--

ALTER TABLE ONLY billing.documents
    ADD CONSTRAINT documents_source_order_id_fkey FOREIGN KEY (source_order_id) REFERENCES sales.sales_orders(id);


--
-- Name: branches branches_company_id_fkey; Type: FK CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.branches
    ADD CONSTRAINT branches_company_id_fkey FOREIGN KEY (company_id) REFERENCES core.companies(id);


--
-- Name: company_igv_rates company_igv_rates_company_id_fkey; Type: FK CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.company_igv_rates
    ADD CONSTRAINT company_igv_rates_company_id_fkey FOREIGN KEY (company_id) REFERENCES core.companies(id) ON DELETE CASCADE;


--
-- Name: units units_sunat_uom_code_fkey; Type: FK CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.units
    ADD CONSTRAINT units_sunat_uom_code_fkey FOREIGN KEY (sunat_uom_code) REFERENCES master.sunat_uom(code);


--
-- Name: categories categories_company_id_fkey; Type: FK CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.categories
    ADD CONSTRAINT categories_company_id_fkey FOREIGN KEY (company_id) REFERENCES core.companies(id);


--
-- Name: inventory_ledger inventory_ledger_company_id_fkey; Type: FK CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.inventory_ledger
    ADD CONSTRAINT inventory_ledger_company_id_fkey FOREIGN KEY (company_id) REFERENCES core.companies(id);


--
-- Name: inventory_ledger inventory_ledger_created_by_fkey; Type: FK CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.inventory_ledger
    ADD CONSTRAINT inventory_ledger_created_by_fkey FOREIGN KEY (created_by) REFERENCES auth.users(id);


--
-- Name: inventory_ledger inventory_ledger_lot_id_fkey; Type: FK CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.inventory_ledger
    ADD CONSTRAINT inventory_ledger_lot_id_fkey FOREIGN KEY (lot_id) REFERENCES inventory.product_lots(id);


--
-- Name: inventory_ledger inventory_ledger_product_id_fkey; Type: FK CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.inventory_ledger
    ADD CONSTRAINT inventory_ledger_product_id_fkey FOREIGN KEY (product_id) REFERENCES inventory.products(id);


--
-- Name: inventory_ledger inventory_ledger_warehouse_id_fkey; Type: FK CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.inventory_ledger
    ADD CONSTRAINT inventory_ledger_warehouse_id_fkey FOREIGN KEY (warehouse_id) REFERENCES inventory.warehouses(id);


--
-- Name: inventory_settings inventory_settings_company_id_fkey; Type: FK CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.inventory_settings
    ADD CONSTRAINT inventory_settings_company_id_fkey FOREIGN KEY (company_id) REFERENCES core.companies(id);


--
-- Name: product_lots product_lots_company_id_fkey; Type: FK CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.product_lots
    ADD CONSTRAINT product_lots_company_id_fkey FOREIGN KEY (company_id) REFERENCES core.companies(id);


--
-- Name: product_lots product_lots_created_by_fkey; Type: FK CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.product_lots
    ADD CONSTRAINT product_lots_created_by_fkey FOREIGN KEY (created_by) REFERENCES auth.users(id);


--
-- Name: product_lots product_lots_product_id_fkey; Type: FK CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.product_lots
    ADD CONSTRAINT product_lots_product_id_fkey FOREIGN KEY (product_id) REFERENCES inventory.products(id);


--
-- Name: product_lots product_lots_warehouse_id_fkey; Type: FK CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.product_lots
    ADD CONSTRAINT product_lots_warehouse_id_fkey FOREIGN KEY (warehouse_id) REFERENCES inventory.warehouses(id);


--
-- Name: product_recipe_items product_recipe_items_component_product_id_fkey; Type: FK CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.product_recipe_items
    ADD CONSTRAINT product_recipe_items_component_product_id_fkey FOREIGN KEY (component_product_id) REFERENCES inventory.products(id);


--
-- Name: product_recipe_items product_recipe_items_recipe_id_fkey; Type: FK CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.product_recipe_items
    ADD CONSTRAINT product_recipe_items_recipe_id_fkey FOREIGN KEY (recipe_id) REFERENCES inventory.product_recipes(id) ON DELETE CASCADE;


--
-- Name: product_recipes product_recipes_company_id_fkey; Type: FK CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.product_recipes
    ADD CONSTRAINT product_recipes_company_id_fkey FOREIGN KEY (company_id) REFERENCES core.companies(id) ON DELETE CASCADE;


--
-- Name: product_recipes product_recipes_created_by_fkey; Type: FK CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.product_recipes
    ADD CONSTRAINT product_recipes_created_by_fkey FOREIGN KEY (created_by) REFERENCES auth.users(id);


--
-- Name: product_recipes product_recipes_output_product_id_fkey; Type: FK CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.product_recipes
    ADD CONSTRAINT product_recipes_output_product_id_fkey FOREIGN KEY (output_product_id) REFERENCES inventory.products(id);


--
-- Name: product_uom_conversions product_uom_conversions_company_id_fkey; Type: FK CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.product_uom_conversions
    ADD CONSTRAINT product_uom_conversions_company_id_fkey FOREIGN KEY (company_id) REFERENCES core.companies(id) ON DELETE CASCADE;


--
-- Name: product_uom_conversions product_uom_conversions_from_unit_id_fkey; Type: FK CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.product_uom_conversions
    ADD CONSTRAINT product_uom_conversions_from_unit_id_fkey FOREIGN KEY (from_unit_id) REFERENCES core.units(id);


--
-- Name: product_uom_conversions product_uom_conversions_product_id_fkey; Type: FK CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.product_uom_conversions
    ADD CONSTRAINT product_uom_conversions_product_id_fkey FOREIGN KEY (product_id) REFERENCES inventory.products(id) ON DELETE CASCADE;


--
-- Name: product_uom_conversions product_uom_conversions_to_unit_id_fkey; Type: FK CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.product_uom_conversions
    ADD CONSTRAINT product_uom_conversions_to_unit_id_fkey FOREIGN KEY (to_unit_id) REFERENCES core.units(id);


--
-- Name: products products_category_id_fkey; Type: FK CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.products
    ADD CONSTRAINT products_category_id_fkey FOREIGN KEY (category_id) REFERENCES inventory.categories(id);


--
-- Name: products products_company_id_fkey; Type: FK CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.products
    ADD CONSTRAINT products_company_id_fkey FOREIGN KEY (company_id) REFERENCES core.companies(id);


--
-- Name: products products_unit_id_fkey; Type: FK CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.products
    ADD CONSTRAINT products_unit_id_fkey FOREIGN KEY (unit_id) REFERENCES core.units(id);


--
-- Name: stock_transformation_lines stock_transformation_lines_lot_id_fkey; Type: FK CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.stock_transformation_lines
    ADD CONSTRAINT stock_transformation_lines_lot_id_fkey FOREIGN KEY (lot_id) REFERENCES inventory.product_lots(id);


--
-- Name: stock_transformation_lines stock_transformation_lines_product_id_fkey; Type: FK CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.stock_transformation_lines
    ADD CONSTRAINT stock_transformation_lines_product_id_fkey FOREIGN KEY (product_id) REFERENCES inventory.products(id);


--
-- Name: stock_transformation_lines stock_transformation_lines_transformation_id_fkey; Type: FK CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.stock_transformation_lines
    ADD CONSTRAINT stock_transformation_lines_transformation_id_fkey FOREIGN KEY (transformation_id) REFERENCES inventory.stock_transformations(id) ON DELETE CASCADE;


--
-- Name: stock_transformation_lines stock_transformation_lines_unit_id_fkey; Type: FK CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.stock_transformation_lines
    ADD CONSTRAINT stock_transformation_lines_unit_id_fkey FOREIGN KEY (unit_id) REFERENCES core.units(id);


--
-- Name: stock_transformations stock_transformations_branch_id_fkey; Type: FK CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.stock_transformations
    ADD CONSTRAINT stock_transformations_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES core.branches(id);


--
-- Name: stock_transformations stock_transformations_company_id_fkey; Type: FK CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.stock_transformations
    ADD CONSTRAINT stock_transformations_company_id_fkey FOREIGN KEY (company_id) REFERENCES core.companies(id) ON DELETE CASCADE;


--
-- Name: stock_transformations stock_transformations_created_by_fkey; Type: FK CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.stock_transformations
    ADD CONSTRAINT stock_transformations_created_by_fkey FOREIGN KEY (created_by) REFERENCES auth.users(id);


--
-- Name: stock_transformations stock_transformations_recipe_id_fkey; Type: FK CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.stock_transformations
    ADD CONSTRAINT stock_transformations_recipe_id_fkey FOREIGN KEY (recipe_id) REFERENCES inventory.product_recipes(id);


--
-- Name: stock_transformations stock_transformations_warehouse_id_fkey; Type: FK CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.stock_transformations
    ADD CONSTRAINT stock_transformations_warehouse_id_fkey FOREIGN KEY (warehouse_id) REFERENCES inventory.warehouses(id);


--
-- Name: transformation_settings transformation_settings_company_id_fkey; Type: FK CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.transformation_settings
    ADD CONSTRAINT transformation_settings_company_id_fkey FOREIGN KEY (company_id) REFERENCES core.companies(id) ON DELETE CASCADE;


--
-- Name: warehouses warehouses_branch_id_fkey; Type: FK CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.warehouses
    ADD CONSTRAINT warehouses_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES core.branches(id);


--
-- Name: warehouses warehouses_company_id_fkey; Type: FK CONSTRAINT; Schema: inventory; Owner: postgres
--

ALTER TABLE ONLY inventory.warehouses
    ADD CONSTRAINT warehouses_company_id_fkey FOREIGN KEY (company_id) REFERENCES core.companies(id);


--
-- Name: cash_movements cash_movements_cash_session_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.cash_movements
    ADD CONSTRAINT cash_movements_cash_session_id_fkey FOREIGN KEY (cash_session_id) REFERENCES sales.cash_sessions(id) ON DELETE CASCADE;


--
-- Name: cash_movements cash_movements_created_by_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.cash_movements
    ADD CONSTRAINT cash_movements_created_by_fkey FOREIGN KEY (created_by) REFERENCES auth.users(id);


--
-- Name: cash_movements cash_movements_payment_method_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.cash_movements
    ADD CONSTRAINT cash_movements_payment_method_id_fkey FOREIGN KEY (payment_method_id) REFERENCES core.payment_methods(id);


--
-- Name: cash_registers cash_registers_branch_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.cash_registers
    ADD CONSTRAINT cash_registers_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES core.branches(id);


--
-- Name: cash_registers cash_registers_company_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.cash_registers
    ADD CONSTRAINT cash_registers_company_id_fkey FOREIGN KEY (company_id) REFERENCES core.companies(id);


--
-- Name: cash_sessions cash_sessions_branch_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.cash_sessions
    ADD CONSTRAINT cash_sessions_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES core.branches(id);


--
-- Name: cash_sessions cash_sessions_cash_register_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.cash_sessions
    ADD CONSTRAINT cash_sessions_cash_register_id_fkey FOREIGN KEY (cash_register_id) REFERENCES sales.cash_registers(id);


--
-- Name: cash_sessions cash_sessions_closed_by_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.cash_sessions
    ADD CONSTRAINT cash_sessions_closed_by_fkey FOREIGN KEY (closed_by) REFERENCES auth.users(id);


--
-- Name: cash_sessions cash_sessions_company_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.cash_sessions
    ADD CONSTRAINT cash_sessions_company_id_fkey FOREIGN KEY (company_id) REFERENCES core.companies(id);


--
-- Name: cash_sessions cash_sessions_opened_by_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.cash_sessions
    ADD CONSTRAINT cash_sessions_opened_by_fkey FOREIGN KEY (opened_by) REFERENCES auth.users(id);


--
-- Name: commercial_document_item_lots commercial_document_item_lots_document_item_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.commercial_document_item_lots
    ADD CONSTRAINT commercial_document_item_lots_document_item_id_fkey FOREIGN KEY (document_item_id) REFERENCES sales.commercial_document_items(id) ON DELETE CASCADE;


--
-- Name: commercial_document_item_lots commercial_document_item_lots_lot_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.commercial_document_item_lots
    ADD CONSTRAINT commercial_document_item_lots_lot_id_fkey FOREIGN KEY (lot_id) REFERENCES inventory.product_lots(id);


--
-- Name: commercial_document_items commercial_document_items_document_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.commercial_document_items
    ADD CONSTRAINT commercial_document_items_document_id_fkey FOREIGN KEY (document_id) REFERENCES sales.commercial_documents(id) ON DELETE CASCADE;


--
-- Name: commercial_document_items commercial_document_items_price_tier_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.commercial_document_items
    ADD CONSTRAINT commercial_document_items_price_tier_id_fkey FOREIGN KEY (price_tier_id) REFERENCES sales.price_tiers(id);


--
-- Name: commercial_document_items commercial_document_items_product_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.commercial_document_items
    ADD CONSTRAINT commercial_document_items_product_id_fkey FOREIGN KEY (product_id) REFERENCES inventory.products(id);


--
-- Name: commercial_document_items commercial_document_items_tax_category_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.commercial_document_items
    ADD CONSTRAINT commercial_document_items_tax_category_id_fkey FOREIGN KEY (tax_category_id) REFERENCES master.vat_categories(id);


--
-- Name: commercial_document_items commercial_document_items_unit_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.commercial_document_items
    ADD CONSTRAINT commercial_document_items_unit_id_fkey FOREIGN KEY (unit_id) REFERENCES core.units(id);


--
-- Name: commercial_document_payments commercial_document_payments_document_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.commercial_document_payments
    ADD CONSTRAINT commercial_document_payments_document_id_fkey FOREIGN KEY (document_id) REFERENCES sales.commercial_documents(id) ON DELETE CASCADE;


--
-- Name: commercial_document_payments commercial_document_payments_payment_method_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.commercial_document_payments
    ADD CONSTRAINT commercial_document_payments_payment_method_id_fkey FOREIGN KEY (payment_method_id) REFERENCES core.payment_methods(id);


--
-- Name: commercial_documents commercial_documents_branch_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.commercial_documents
    ADD CONSTRAINT commercial_documents_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES core.branches(id);


--
-- Name: commercial_documents commercial_documents_company_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.commercial_documents
    ADD CONSTRAINT commercial_documents_company_id_fkey FOREIGN KEY (company_id) REFERENCES core.companies(id);


--
-- Name: commercial_documents commercial_documents_created_by_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.commercial_documents
    ADD CONSTRAINT commercial_documents_created_by_fkey FOREIGN KEY (created_by) REFERENCES auth.users(id);


--
-- Name: commercial_documents commercial_documents_currency_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.commercial_documents
    ADD CONSTRAINT commercial_documents_currency_id_fkey FOREIGN KEY (currency_id) REFERENCES core.currencies(id);


--
-- Name: commercial_documents commercial_documents_customer_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.commercial_documents
    ADD CONSTRAINT commercial_documents_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES sales.customers(id);


--
-- Name: commercial_documents commercial_documents_payment_method_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.commercial_documents
    ADD CONSTRAINT commercial_documents_payment_method_id_fkey FOREIGN KEY (payment_method_id) REFERENCES core.payment_methods(id);


--
-- Name: commercial_documents commercial_documents_reference_document_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.commercial_documents
    ADD CONSTRAINT commercial_documents_reference_document_id_fkey FOREIGN KEY (reference_document_id) REFERENCES sales.commercial_documents(id);


--
-- Name: commercial_documents commercial_documents_seller_user_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.commercial_documents
    ADD CONSTRAINT commercial_documents_seller_user_id_fkey FOREIGN KEY (seller_user_id) REFERENCES auth.users(id);


--
-- Name: commercial_documents commercial_documents_source_document_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.commercial_documents
    ADD CONSTRAINT commercial_documents_source_document_id_fkey FOREIGN KEY (source_document_id) REFERENCES sales.commercial_documents(id);


--
-- Name: commercial_documents commercial_documents_updated_by_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.commercial_documents
    ADD CONSTRAINT commercial_documents_updated_by_fkey FOREIGN KEY (updated_by) REFERENCES auth.users(id);


--
-- Name: commercial_documents commercial_documents_warehouse_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.commercial_documents
    ADD CONSTRAINT commercial_documents_warehouse_id_fkey FOREIGN KEY (warehouse_id) REFERENCES inventory.warehouses(id);


--
-- Name: customer_price_profiles customer_price_profiles_company_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.customer_price_profiles
    ADD CONSTRAINT customer_price_profiles_company_id_fkey FOREIGN KEY (company_id) REFERENCES core.companies(id) ON DELETE CASCADE;


--
-- Name: customer_price_profiles customer_price_profiles_customer_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.customer_price_profiles
    ADD CONSTRAINT customer_price_profiles_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES sales.customers(id) ON DELETE CASCADE;


--
-- Name: customer_price_profiles customer_price_profiles_default_tier_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.customer_price_profiles
    ADD CONSTRAINT customer_price_profiles_default_tier_id_fkey FOREIGN KEY (default_tier_id) REFERENCES sales.price_tiers(id);


--
-- Name: customers customers_company_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.customers
    ADD CONSTRAINT customers_company_id_fkey FOREIGN KEY (company_id) REFERENCES core.companies(id);


--
-- Name: customers customers_customer_type_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.customers
    ADD CONSTRAINT customers_customer_type_id_fkey FOREIGN KEY (customer_type_id) REFERENCES sales.customer_types(id);


--
-- Name: document_sequences document_sequences_branch_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.document_sequences
    ADD CONSTRAINT document_sequences_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES core.branches(id);


--
-- Name: document_sequences document_sequences_company_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.document_sequences
    ADD CONSTRAINT document_sequences_company_id_fkey FOREIGN KEY (company_id) REFERENCES core.companies(id);


--
-- Name: document_sequences document_sequences_warehouse_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.document_sequences
    ADD CONSTRAINT document_sequences_warehouse_id_fkey FOREIGN KEY (warehouse_id) REFERENCES inventory.warehouses(id);


--
-- Name: order_sequences order_sequences_branch_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.order_sequences
    ADD CONSTRAINT order_sequences_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES core.branches(id);


--
-- Name: order_sequences order_sequences_company_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.order_sequences
    ADD CONSTRAINT order_sequences_company_id_fkey FOREIGN KEY (company_id) REFERENCES core.companies(id);


--
-- Name: price_tiers price_tiers_company_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.price_tiers
    ADD CONSTRAINT price_tiers_company_id_fkey FOREIGN KEY (company_id) REFERENCES core.companies(id) ON DELETE CASCADE;


--
-- Name: product_tier_prices product_tier_prices_company_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.product_tier_prices
    ADD CONSTRAINT product_tier_prices_company_id_fkey FOREIGN KEY (company_id) REFERENCES core.companies(id) ON DELETE CASCADE;


--
-- Name: product_tier_prices product_tier_prices_currency_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.product_tier_prices
    ADD CONSTRAINT product_tier_prices_currency_id_fkey FOREIGN KEY (currency_id) REFERENCES core.currencies(id);


--
-- Name: product_tier_prices product_tier_prices_product_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.product_tier_prices
    ADD CONSTRAINT product_tier_prices_product_id_fkey FOREIGN KEY (product_id) REFERENCES inventory.products(id) ON DELETE CASCADE;


--
-- Name: product_tier_prices product_tier_prices_tier_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.product_tier_prices
    ADD CONSTRAINT product_tier_prices_tier_id_fkey FOREIGN KEY (tier_id) REFERENCES sales.price_tiers(id) ON DELETE CASCADE;


--
-- Name: sales_order_item_lots sales_order_item_lots_lot_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.sales_order_item_lots
    ADD CONSTRAINT sales_order_item_lots_lot_id_fkey FOREIGN KEY (lot_id) REFERENCES inventory.product_lots(id);


--
-- Name: sales_order_item_lots sales_order_item_lots_sales_order_item_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.sales_order_item_lots
    ADD CONSTRAINT sales_order_item_lots_sales_order_item_id_fkey FOREIGN KEY (sales_order_item_id) REFERENCES sales.sales_order_items(id) ON DELETE CASCADE;


--
-- Name: sales_order_items sales_order_items_order_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.sales_order_items
    ADD CONSTRAINT sales_order_items_order_id_fkey FOREIGN KEY (order_id) REFERENCES sales.sales_orders(id) ON DELETE CASCADE;


--
-- Name: sales_order_items sales_order_items_product_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.sales_order_items
    ADD CONSTRAINT sales_order_items_product_id_fkey FOREIGN KEY (product_id) REFERENCES inventory.products(id);


--
-- Name: sales_order_items sales_order_items_unit_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.sales_order_items
    ADD CONSTRAINT sales_order_items_unit_id_fkey FOREIGN KEY (unit_id) REFERENCES core.units(id);


--
-- Name: sales_order_payments sales_order_payments_order_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.sales_order_payments
    ADD CONSTRAINT sales_order_payments_order_id_fkey FOREIGN KEY (order_id) REFERENCES sales.sales_orders(id) ON DELETE CASCADE;


--
-- Name: sales_order_payments sales_order_payments_payment_method_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.sales_order_payments
    ADD CONSTRAINT sales_order_payments_payment_method_id_fkey FOREIGN KEY (payment_method_id) REFERENCES core.payment_methods(id);


--
-- Name: sales_orders sales_orders_branch_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.sales_orders
    ADD CONSTRAINT sales_orders_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES core.branches(id);


--
-- Name: sales_orders sales_orders_company_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.sales_orders
    ADD CONSTRAINT sales_orders_company_id_fkey FOREIGN KEY (company_id) REFERENCES core.companies(id);


--
-- Name: sales_orders sales_orders_created_by_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.sales_orders
    ADD CONSTRAINT sales_orders_created_by_fkey FOREIGN KEY (created_by) REFERENCES auth.users(id);


--
-- Name: sales_orders sales_orders_currency_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.sales_orders
    ADD CONSTRAINT sales_orders_currency_id_fkey FOREIGN KEY (currency_id) REFERENCES core.currencies(id);


--
-- Name: sales_orders sales_orders_customer_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.sales_orders
    ADD CONSTRAINT sales_orders_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES sales.customers(id);


--
-- Name: sales_orders sales_orders_payment_method_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.sales_orders
    ADD CONSTRAINT sales_orders_payment_method_id_fkey FOREIGN KEY (payment_method_id) REFERENCES core.payment_methods(id);


--
-- Name: sales_orders sales_orders_seller_user_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.sales_orders
    ADD CONSTRAINT sales_orders_seller_user_id_fkey FOREIGN KEY (seller_user_id) REFERENCES auth.users(id);


--
-- Name: sales_orders sales_orders_updated_by_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.sales_orders
    ADD CONSTRAINT sales_orders_updated_by_fkey FOREIGN KEY (updated_by) REFERENCES auth.users(id);


--
-- Name: sales_orders sales_orders_warehouse_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.sales_orders
    ADD CONSTRAINT sales_orders_warehouse_id_fkey FOREIGN KEY (warehouse_id) REFERENCES inventory.warehouses(id);


--
-- Name: series_numbers series_numbers_branch_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.series_numbers
    ADD CONSTRAINT series_numbers_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES core.branches(id);


--
-- Name: series_numbers series_numbers_company_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.series_numbers
    ADD CONSTRAINT series_numbers_company_id_fkey FOREIGN KEY (company_id) REFERENCES core.companies(id);


--
-- Name: series_numbers series_numbers_updated_by_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.series_numbers
    ADD CONSTRAINT series_numbers_updated_by_fkey FOREIGN KEY (updated_by) REFERENCES auth.users(id);


--
-- Name: series_numbers series_numbers_warehouse_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.series_numbers
    ADD CONSTRAINT series_numbers_warehouse_id_fkey FOREIGN KEY (warehouse_id) REFERENCES inventory.warehouses(id);


--
-- Name: wholesale_settings wholesale_settings_company_id_fkey; Type: FK CONSTRAINT; Schema: sales; Owner: postgres
--

ALTER TABLE ONLY sales.wholesale_settings
    ADD CONSTRAINT wholesale_settings_company_id_fkey FOREIGN KEY (company_id) REFERENCES core.companies(id) ON DELETE CASCADE;


--
-- PostgreSQL database dump complete
--

\unrestrict Q8excKmk7D3fluEXNfdQNnxQydW5s8QMTDcIj8HcqufPqsq1LjSbDaSYecM7NoY

