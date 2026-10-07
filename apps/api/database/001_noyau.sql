-- Clientèle Group ERP - contrat PostgreSQL du noyau
-- Cette migration est un référentiel de conception. Elle sera transcrite
-- en migrations Laravel sans affaiblir les contraintes ni les politiques RLS.

CREATE EXTENSION IF NOT EXISTS pgcrypto;

CREATE TYPE core_currency AS ENUM ('HTG', 'USD');
CREATE TYPE core_receipt_status AS ENUM (
  'DRAFT',
  'PENDING_SYNC',
  'CONFIRMED',
  'VOIDED',
  'REFUNDED'
);
CREATE TYPE core_cash_session_status AS ENUM ('OPEN', 'CLOSED', 'RECONCILED');
CREATE TYPE core_sync_status AS ENUM ('QUEUED', 'ACCEPTED', 'REJECTED', 'CONFLICT');
CREATE TYPE core_actor_type AS ENUM ('USER', 'DEVICE', 'SYSTEM');
CREATE TYPE core_rate_source AS ENUM ('BRH_REFERENCE', 'MANUAL', 'APPROVED_IMPORT');
CREATE TYPE core_customer_consent_status AS ENUM ('GRANTED', 'REVOKED', 'EXPIRED');
CREATE TYPE core_customer_data_scope AS ENUM ('IDENTITY_CONTACT', 'PREFERENCES', 'MARKETING');

CREATE TABLE companies (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  code varchar(32) NOT NULL UNIQUE,
  legal_name text NOT NULL,
  display_name text NOT NULL,
  base_currency core_currency NOT NULL DEFAULT 'HTG',
  timezone text NOT NULL DEFAULT 'America/Port-au-Prince',
  locale text NOT NULL DEFAULT 'fr-HT',
  is_active boolean NOT NULL DEFAULT true,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE sites (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  company_id uuid NOT NULL REFERENCES companies(id),
  code varchar(32) NOT NULL,
  name text NOT NULL,
  address text,
  is_active boolean NOT NULL DEFAULT true,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (company_id, code),
  UNIQUE (id, company_id)
);

CREATE TABLE cash_registers (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  company_id uuid NOT NULL REFERENCES companies(id),
  site_id uuid NOT NULL,
  code varchar(32) NOT NULL,
  name text NOT NULL,
  device_id uuid,
  automatic_print_enabled boolean NOT NULL DEFAULT false,
  customer_printer_profile text,
  admin_printer_profile text,
  customer_display_enabled boolean NOT NULL DEFAULT false,
  is_active boolean NOT NULL DEFAULT true,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (company_id, code),
  UNIQUE (id, company_id),
  FOREIGN KEY (site_id, company_id) REFERENCES sites(id, company_id)
);

CREATE TABLE exchange_rate_references (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  company_id uuid NOT NULL REFERENCES companies(id),
  rate_date date NOT NULL,
  base_currency core_currency NOT NULL DEFAULT 'USD',
  quote_currency core_currency NOT NULL DEFAULT 'HTG',
  rate numeric(20, 8) NOT NULL CHECK (rate > 0),
  source core_rate_source NOT NULL DEFAULT 'BRH_REFERENCE',
  source_reference text,
  captured_by_uuid uuid,
  captured_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (company_id, rate_date, base_currency, quote_currency, source)
);

CREATE TABLE exchange_rates (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  company_id uuid NOT NULL REFERENCES companies(id),
  effective_at timestamptz NOT NULL,
  base_currency core_currency NOT NULL DEFAULT 'USD',
  quote_currency core_currency NOT NULL DEFAULT 'HTG',
  rate numeric(20, 8) NOT NULL CHECK (rate > 0),
  reference_rate_id uuid REFERENCES exchange_rate_references(id),
  below_reference boolean NOT NULL DEFAULT false,
  override_reason text,
  created_by_uuid uuid NOT NULL,
  created_at timestamptz NOT NULL DEFAULT now(),
  CHECK (
    (below_reference = false AND override_reason IS NULL)
    OR (below_reference = true AND length(trim(override_reason)) > 0)
  )
);

CREATE TABLE receipt_number_sequences (
  company_id uuid PRIMARY KEY REFERENCES companies(id),
  last_number integer NOT NULL DEFAULT 0 CHECK (last_number >= 0 AND last_number <= 99999999),
  updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE OR REPLACE FUNCTION next_receipt_number(p_company_id uuid)
RETURNS varchar(8)
LANGUAGE plpgsql
AS $function$
DECLARE
  v_next integer;
BEGIN
  INSERT INTO receipt_number_sequences (company_id)
  VALUES (p_company_id)
  ON CONFLICT (company_id) DO NOTHING;

  UPDATE receipt_number_sequences
  SET last_number = last_number + 1,
      updated_at = now()
  WHERE company_id = p_company_id
    AND last_number < 99999999
  RETURNING last_number INTO v_next;

  IF NOT FOUND THEN
    RAISE EXCEPTION 'La séquence de reçu de la société % est épuisée', p_company_id;
  END IF;

  RETURN lpad(v_next::text, 8, '0');
END;
$function$;

-- L'identité maître ne contient aucun historique opérationnel. Les colonnes
-- chiffrées et les empreintes HMAC sont réservées au service de confidentialité
-- de groupe : elles ne sont jamais interrogées directement par un poste de caisse.
CREATE TABLE group_customer_identities (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  group_reference varchar(32) NOT NULL UNIQUE,
  identity_data_encrypted bytea NOT NULL,
  email_lookup_hmac char(64),
  phone_lookup_hmac char(64),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX group_customer_identities_email_lookup_idx
  ON group_customer_identities (email_lookup_hmac)
  WHERE email_lookup_hmac IS NOT NULL;
CREATE INDEX group_customer_identities_phone_lookup_idx
  ON group_customer_identities (phone_lookup_hmac)
  WHERE phone_lookup_hmac IS NOT NULL;

CREATE TABLE company_customers (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  company_id uuid NOT NULL REFERENCES companies(id),
  group_customer_id uuid REFERENCES group_customer_identities(id),
  local_reference varchar(32) NOT NULL,
  local_profile_encrypted bytea NOT NULL,
  is_active boolean NOT NULL DEFAULT true,
  linked_at timestamptz,
  linked_by_uuid uuid,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (company_id, local_reference),
  UNIQUE (company_id, group_customer_id),
  UNIQUE (id, company_id)
);

-- Le consentement est spécifique à une société destinataire et à une catégorie
-- de données. Il ne donne jamais accès aux ventes, documents ou soldes.
CREATE TABLE customer_sharing_consents (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  group_customer_id uuid NOT NULL REFERENCES group_customer_identities(id),
  recipient_company_id uuid NOT NULL REFERENCES companies(id),
  data_scope core_customer_data_scope NOT NULL,
  status core_customer_consent_status NOT NULL DEFAULT 'GRANTED',
  purpose text NOT NULL,
  granted_at timestamptz NOT NULL DEFAULT now(),
  expires_at timestamptz,
  revoked_at timestamptz,
  supersedes_consent_id uuid REFERENCES customer_sharing_consents(id),
  evidence_encrypted bytea NOT NULL,
  captured_by_uuid uuid NOT NULL,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  CHECK (expires_at IS NULL OR expires_at > granted_at),
  CHECK (
    (status = 'GRANTED' AND revoked_at IS NULL)
    OR (status = 'REVOKED' AND revoked_at IS NOT NULL)
    OR status = 'EXPIRED'
  )
);

COMMENT ON TABLE group_customer_identities IS
  'Identités maître Clientèle Group : accès réservé au service de confidentialité, jamais à un tenant applicatif.';
COMMENT ON TABLE company_customers IS
  'Profils clients locaux protégés par la société active.';
COMMENT ON TABLE customer_sharing_consents IS
  'Consentements de partage de données non opérationnelles entre groupe et société.';

REVOKE ALL ON group_customer_identities, customer_sharing_consents FROM PUBLIC;

CREATE TABLE cash_sessions (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  company_id uuid NOT NULL REFERENCES companies(id),
  site_id uuid NOT NULL,
  cash_register_id uuid NOT NULL,
  status core_cash_session_status NOT NULL DEFAULT 'OPEN',
  opened_by_uuid uuid NOT NULL,
  opened_at timestamptz NOT NULL DEFAULT now(),
  opening_htg numeric(20, 2) NOT NULL DEFAULT 0,
  opening_usd numeric(20, 2) NOT NULL DEFAULT 0,
  closed_by_uuid uuid,
  closed_at timestamptz,
  declared_htg numeric(20, 2),
  declared_usd numeric(20, 2),
  variance_reason text,
  UNIQUE (id, company_id),
  FOREIGN KEY (site_id, company_id) REFERENCES sites(id, company_id),
  FOREIGN KEY (cash_register_id, company_id) REFERENCES cash_registers(id, company_id)
);

CREATE TABLE customer_displays (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  company_id uuid NOT NULL REFERENCES companies(id),
  site_id uuid NOT NULL,
  cash_register_id uuid NOT NULL,
  display_name text NOT NULL,
  secret_token_hash char(64) NOT NULL UNIQUE,
  pairing_code_hash char(64),
  pairing_code_expires_at timestamptz,
  is_active boolean NOT NULL DEFAULT true,
  last_seen_at timestamptz,
  revoked_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (cash_register_id),
  FOREIGN KEY (site_id, company_id) REFERENCES sites(id, company_id),
  FOREIGN KEY (cash_register_id, company_id) REFERENCES cash_registers(id, company_id)
);

CREATE TABLE receipt_number_blocks (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  company_id uuid NOT NULL REFERENCES companies(id),
  cash_register_id uuid NOT NULL,
  first_number varchar(8) NOT NULL CHECK (first_number ~ '^[0-9]{8}$'),
  last_number varchar(8) NOT NULL CHECK (last_number ~ '^[0-9]{8}$'),
  next_number varchar(8) CHECK (next_number ~ '^[0-9]{8}$'),
  valid_until timestamptz NOT NULL,
  assigned_by_uuid uuid NOT NULL,
  assigned_at timestamptz NOT NULL DEFAULT now(),
  revoked_at timestamptz,
  CHECK (first_number <= last_number),
  CHECK (
    next_number IS NULL
    OR (next_number >= first_number AND next_number <= last_number)
  ),
  FOREIGN KEY (cash_register_id, company_id) REFERENCES cash_registers(id, company_id)
);

CREATE TABLE receipts (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  public_id uuid NOT NULL DEFAULT gen_random_uuid() UNIQUE,
  company_id uuid NOT NULL REFERENCES companies(id),
  site_id uuid NOT NULL,
  cash_register_id uuid NOT NULL,
  cash_session_id uuid,
  customer_id uuid,
  receipt_number_raw varchar(8) NOT NULL CHECK (receipt_number_raw ~ '^[0-9]{8}$'),
  receipt_number_display varchar(9) GENERATED ALWAYS AS (
    substring(receipt_number_raw FROM 1 FOR 4)
    || ' '
    || substring(receipt_number_raw FROM 5 FOR 4)
  ) STORED,
  status core_receipt_status NOT NULL DEFAULT 'DRAFT',
  currency core_currency NOT NULL,
  subtotal_amount numeric(20, 2) NOT NULL DEFAULT 0,
  discount_amount numeric(20, 2) NOT NULL DEFAULT 0,
  tax_amount numeric(20, 2) NOT NULL DEFAULT 0,
  total_amount numeric(20, 2) NOT NULL DEFAULT 0,
  item_count integer NOT NULL DEFAULT 0 CHECK (item_count >= 0),
  exchange_rate_id uuid REFERENCES exchange_rates(id),
  qr_signature_hash char(64) NOT NULL,
  offline_operation_id uuid,
  issued_at timestamptz,
  issued_timezone text NOT NULL DEFAULT 'America/Port-au-Prince',
  confirmed_by_uuid uuid,
  voided_at timestamptz,
  void_reason text,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (company_id, receipt_number_raw),
  UNIQUE (id, company_id),
  FOREIGN KEY (site_id, company_id) REFERENCES sites(id, company_id),
  FOREIGN KEY (cash_register_id, company_id) REFERENCES cash_registers(id, company_id),
  FOREIGN KEY (cash_session_id, company_id) REFERENCES cash_sessions(id, company_id),
  FOREIGN KEY (customer_id, company_id) REFERENCES company_customers(id, company_id)
);

CREATE TABLE receipt_lines (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  company_id uuid NOT NULL REFERENCES companies(id),
  receipt_id uuid NOT NULL,
  line_number integer NOT NULL CHECK (line_number > 0),
  item_type text NOT NULL,
  item_id uuid,
  label text NOT NULL,
  quantity numeric(20, 3) NOT NULL CHECK (quantity > 0),
  unit_price numeric(20, 2) NOT NULL,
  discount_amount numeric(20, 2) NOT NULL DEFAULT 0,
  tax_amount numeric(20, 2) NOT NULL DEFAULT 0,
  line_total numeric(20, 2) NOT NULL,
  metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
  UNIQUE (receipt_id, line_number),
  FOREIGN KEY (receipt_id, company_id) REFERENCES receipts(id, company_id)
);

CREATE TABLE receipt_payments (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  company_id uuid NOT NULL REFERENCES companies(id),
  receipt_id uuid NOT NULL,
  payment_method text NOT NULL,
  currency core_currency NOT NULL,
  amount numeric(20, 2) NOT NULL CHECK (amount > 0),
  exchange_rate numeric(20, 8),
  base_amount numeric(20, 2),
  provider_reference text,
  received_by_uuid uuid,
  received_at timestamptz NOT NULL DEFAULT now(),
  metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
  FOREIGN KEY (receipt_id, company_id) REFERENCES receipts(id, company_id)
);

CREATE TABLE offline_operations (
  id uuid PRIMARY KEY,
  company_id uuid NOT NULL REFERENCES companies(id),
  cash_register_id uuid NOT NULL,
  device_id uuid NOT NULL,
  idempotency_key uuid NOT NULL,
  payload_sha256 char(64) NOT NULL,
  status core_sync_status NOT NULL DEFAULT 'QUEUED',
  received_at timestamptz NOT NULL DEFAULT now(),
  resolved_at timestamptz,
  error_code text,
  error_message text,
  UNIQUE (company_id, device_id, idempotency_key),
  FOREIGN KEY (cash_register_id, company_id) REFERENCES cash_registers(id, company_id)
);

CREATE TABLE audit_events (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  company_id uuid NOT NULL REFERENCES companies(id),
  site_id uuid,
  actor_type core_actor_type NOT NULL,
  actor_uuid uuid,
  device_id uuid,
  request_id uuid,
  correlation_id uuid NOT NULL DEFAULT gen_random_uuid(),
  event_type text NOT NULL,
  entity_type text NOT NULL,
  entity_id uuid,
  outcome text NOT NULL,
  reason text,
  amount numeric(20, 2),
  currency core_currency,
  before_data jsonb,
  after_data jsonb,
  metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
  ip_address inet,
  user_agent text,
  occurred_at timestamptz NOT NULL DEFAULT now(),
  display_timezone text NOT NULL DEFAULT 'America/Port-au-Prince',
  FOREIGN KEY (site_id, company_id) REFERENCES sites(id, company_id)
);

-- Journal distinct pour les actions de rapprochement et de consentement du groupe.
-- Il référence les sociétés impliquées sans exposer les données de profil.
CREATE TABLE group_privacy_audit_events (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  actor_type core_actor_type NOT NULL,
  actor_uuid uuid,
  request_id uuid,
  correlation_id uuid NOT NULL DEFAULT gen_random_uuid(),
  event_type text NOT NULL,
  group_customer_id uuid REFERENCES group_customer_identities(id),
  source_company_id uuid REFERENCES companies(id),
  recipient_company_id uuid REFERENCES companies(id),
  consent_id uuid REFERENCES customer_sharing_consents(id),
  outcome text NOT NULL,
  reason text,
  metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
  ip_address inet,
  user_agent text,
  occurred_at timestamptz NOT NULL DEFAULT now()
);

COMMENT ON TABLE group_privacy_audit_events IS
  'Journal append-only de rapprochement et de partage client ; aucune donnée personnelle en clair.';
REVOKE ALL ON group_privacy_audit_events FROM PUBLIC;

CREATE OR REPLACE FUNCTION reject_audit_mutation()
RETURNS trigger
LANGUAGE plpgsql
AS $function$
BEGIN
  RAISE EXCEPTION 'Les événements d''audit sont immuables';
END;
$function$;

CREATE TRIGGER audit_events_are_append_only
BEFORE UPDATE OR DELETE ON audit_events
FOR EACH ROW EXECUTE FUNCTION reject_audit_mutation();

CREATE TRIGGER group_privacy_audit_events_are_append_only
BEFORE UPDATE OR DELETE ON group_privacy_audit_events
FOR EACH ROW EXECUTE FUNCTION reject_audit_mutation();

CREATE OR REPLACE FUNCTION app_current_company_id()
RETURNS uuid
LANGUAGE sql
STABLE
AS $function$
  SELECT nullif(current_setting('app.company_id', true), '')::uuid;
$function$;

ALTER TABLE sites ENABLE ROW LEVEL SECURITY;
ALTER TABLE sites FORCE ROW LEVEL SECURITY;
CREATE POLICY company_isolation ON sites
  USING (company_id = app_current_company_id())
  WITH CHECK (company_id = app_current_company_id());

ALTER TABLE cash_registers ENABLE ROW LEVEL SECURITY;
ALTER TABLE cash_registers FORCE ROW LEVEL SECURITY;
CREATE POLICY company_isolation ON cash_registers
  USING (company_id = app_current_company_id())
  WITH CHECK (company_id = app_current_company_id());

ALTER TABLE exchange_rate_references ENABLE ROW LEVEL SECURITY;
ALTER TABLE exchange_rate_references FORCE ROW LEVEL SECURITY;
CREATE POLICY company_isolation ON exchange_rate_references
  USING (company_id = app_current_company_id())
  WITH CHECK (company_id = app_current_company_id());

ALTER TABLE exchange_rates ENABLE ROW LEVEL SECURITY;
ALTER TABLE exchange_rates FORCE ROW LEVEL SECURITY;
CREATE POLICY company_isolation ON exchange_rates
  USING (company_id = app_current_company_id())
  WITH CHECK (company_id = app_current_company_id());

ALTER TABLE receipt_number_sequences ENABLE ROW LEVEL SECURITY;
ALTER TABLE receipt_number_sequences FORCE ROW LEVEL SECURITY;
CREATE POLICY company_isolation ON receipt_number_sequences
  USING (company_id = app_current_company_id())
  WITH CHECK (company_id = app_current_company_id());

ALTER TABLE cash_sessions ENABLE ROW LEVEL SECURITY;
ALTER TABLE cash_sessions FORCE ROW LEVEL SECURITY;
CREATE POLICY company_isolation ON cash_sessions
  USING (company_id = app_current_company_id())
  WITH CHECK (company_id = app_current_company_id());

ALTER TABLE company_customers ENABLE ROW LEVEL SECURITY;
ALTER TABLE company_customers FORCE ROW LEVEL SECURITY;
CREATE POLICY company_isolation ON company_customers
  USING (company_id = app_current_company_id())
  WITH CHECK (company_id = app_current_company_id());

ALTER TABLE customer_displays ENABLE ROW LEVEL SECURITY;
ALTER TABLE customer_displays FORCE ROW LEVEL SECURITY;
CREATE POLICY company_isolation ON customer_displays
  USING (company_id = app_current_company_id())
  WITH CHECK (company_id = app_current_company_id());

ALTER TABLE receipt_number_blocks ENABLE ROW LEVEL SECURITY;
ALTER TABLE receipt_number_blocks FORCE ROW LEVEL SECURITY;
CREATE POLICY company_isolation ON receipt_number_blocks
  USING (company_id = app_current_company_id())
  WITH CHECK (company_id = app_current_company_id());

ALTER TABLE receipts ENABLE ROW LEVEL SECURITY;
ALTER TABLE receipts FORCE ROW LEVEL SECURITY;
CREATE POLICY company_isolation ON receipts
  USING (company_id = app_current_company_id())
  WITH CHECK (company_id = app_current_company_id());

ALTER TABLE receipt_lines ENABLE ROW LEVEL SECURITY;
ALTER TABLE receipt_lines FORCE ROW LEVEL SECURITY;
CREATE POLICY company_isolation ON receipt_lines
  USING (company_id = app_current_company_id())
  WITH CHECK (company_id = app_current_company_id());

ALTER TABLE receipt_payments ENABLE ROW LEVEL SECURITY;
ALTER TABLE receipt_payments FORCE ROW LEVEL SECURITY;
CREATE POLICY company_isolation ON receipt_payments
  USING (company_id = app_current_company_id())
  WITH CHECK (company_id = app_current_company_id());

ALTER TABLE offline_operations ENABLE ROW LEVEL SECURITY;
ALTER TABLE offline_operations FORCE ROW LEVEL SECURITY;
CREATE POLICY company_isolation ON offline_operations
  USING (company_id = app_current_company_id())
  WITH CHECK (company_id = app_current_company_id());

ALTER TABLE audit_events ENABLE ROW LEVEL SECURITY;
ALTER TABLE audit_events FORCE ROW LEVEL SECURITY;
CREATE POLICY company_isolation ON audit_events
  USING (company_id = app_current_company_id())
  WITH CHECK (company_id = app_current_company_id());

REVOKE UPDATE, DELETE ON audit_events, group_privacy_audit_events FROM PUBLIC;
