-- Turn connector recovery reads only this revision's latest complete_turn attempts; keep that lookup bounded.
CREATE INDEX provider_attempts_turn_connector_recovery ON lorkhan_internal.provider_attempts USING btree
    (((metadata ->> 'configuration_id'::text)), ((metadata ->> 'configuration_revision'::text)), started_at DESC)
    WHERE ((provider_kind = 'llm'::text) AND (operation = 'complete_turn'::text));
