-- Addon action intents share action_intents/action_delivery/action_results with built-in actions. The plugin columns are
-- all null for built-ins and all set for addons, whose bounded wire contract (tier 0-2, no follow-up) is enforced here too.
ALTER TABLE lorkhan_internal.action_intents
    ADD COLUMN IF NOT EXISTS plugin_id text,
    ADD COLUMN IF NOT EXISTS plugin_version text,
    ADD COLUMN IF NOT EXISTS cancellable boolean;
ALTER TABLE lorkhan_internal.action_intents DROP CONSTRAINT IF EXISTS action_intents_plugin_check;
ALTER TABLE lorkhan_internal.action_intents ADD CONSTRAINT action_intents_plugin_check CHECK (
    (plugin_id IS NULL AND plugin_version IS NULL AND cancellable IS NULL)
    OR (plugin_id IS NOT NULL AND plugin_version IS NOT NULL
        AND plugin_id ~ '^[a-z][a-z0-9_]{1,31}\.[a-z][a-z0-9_]{1,47}$'
        AND plugin_version ~ '^(0|[1-9][0-9]{0,4})\.(0|[1-9][0-9]{0,4})\.(0|[1-9][0-9]{0,4})$'
        AND action_name ~ '^[a-z][a-z0-9_]{0,31}$' AND cancellable IS NOT NULL AND tier <= 2
        AND NOT followup_enabled AND NOT followup_actions_allowed));
-- Withdrawal touches only one session's pending addon intents.
CREATE INDEX IF NOT EXISTS action_intents_pending_plugin ON lorkhan_internal.action_intents (session_id)
    WHERE plugin_id IS NOT NULL AND state <> 'terminal';
ALTER TABLE lorkhan_internal.response_events DROP CONSTRAINT response_events_event_type_check;
ALTER TABLE lorkhan_internal.response_events ADD CONSTRAINT response_events_event_type_check CHECK (event_type = ANY (ARRAY[
    'turn.accepted', 'dialogue.delta', 'dialogue.complete', 'speech.ready', 'speech.failed', 'action.intent',
    'plugin.action.intent', 'response.complete', 'turn.complete', 'turn.failed', 'turn.cancelled',
    'stt.transcript', 'stt.failed', 'director.instructions', 'relationship.adjust']));
