-- Earlier code has no addon registration or event runtime; queued plugin.event.dispatch jobs become unsupported.
DROP TABLE IF EXISTS lorkhan_internal.plugin_events;
DROP FUNCTION IF EXISTS lorkhan_internal.reject_plugin_event_update();
DROP TABLE IF EXISTS lorkhan_internal.plugin_registrations;
