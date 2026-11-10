-- Earlier code has no addon actions. Stored addon intents stay as terminal history, qualified as plugin_id/action so they are
-- never mistaken for built-in actions; their results and delivery rows are kept.
UPDATE lorkhan_internal.action_intents SET action_name = plugin_id || '/' || action_name, state = 'terminal'
WHERE plugin_id IS NOT NULL;
DROP INDEX IF EXISTS lorkhan_internal.action_intents_pending_plugin;
ALTER TABLE lorkhan_internal.action_intents DROP CONSTRAINT IF EXISTS action_intents_plugin_check;
ALTER TABLE lorkhan_internal.action_intents
    DROP COLUMN IF EXISTS cancellable,
    DROP COLUMN IF EXISTS plugin_version,
    DROP COLUMN IF EXISTS plugin_id;
-- Preserve historical addon events while restoring the old constraint for future writes.
ALTER TABLE lorkhan_internal.response_events DROP CONSTRAINT response_events_event_type_check;
ALTER TABLE lorkhan_internal.response_events ADD CONSTRAINT response_events_event_type_check CHECK (event_type = ANY (ARRAY[
    'turn.accepted', 'dialogue.delta', 'dialogue.complete', 'speech.ready', 'speech.failed', 'action.intent',
    'response.complete', 'turn.complete', 'turn.failed', 'turn.cancelled',
    'stt.transcript', 'stt.failed', 'director.instructions', 'relationship.adjust'])) NOT VALID;
