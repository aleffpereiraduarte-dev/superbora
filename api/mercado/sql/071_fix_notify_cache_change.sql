-- Fix notify_cache_change trigger to handle tables with different ID column names.
-- Previously hardcoded NEW.id which broke tables like om_customers (customer_id),
-- om_market_partners (partner_id), om_market_orders (order_id).
CREATE OR REPLACE FUNCTION public.notify_cache_change()
RETURNS trigger
LANGUAGE plpgsql
AS $function$
DECLARE
    payload TEXT;
    rec_id  TEXT;
    rec_jsonb JSONB;
BEGIN
    rec_jsonb := COALESCE(to_jsonb(NEW), to_jsonb(OLD));
    rec_id := COALESCE(
        rec_jsonb->>'id',
        rec_jsonb->>'customer_id',
        rec_jsonb->>'partner_id',
        rec_jsonb->>'order_id',
        rec_jsonb->>'product_id',
        '0'
    );
    payload := json_build_object(
        'table', TG_TABLE_NAME,
        'op', TG_OP,
        'id', rec_id
    )::text;
    BEGIN
        PERFORM pg_notify('cache_invalidation', payload);
    EXCEPTION WHEN OTHERS THEN
        NULL;
    END;
    RETURN COALESCE(NEW, OLD);
END;
$function$;
