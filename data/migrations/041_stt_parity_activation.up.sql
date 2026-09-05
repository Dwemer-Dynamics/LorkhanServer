-- Provision one installation-global Deepgram STT connector without replacing explicit user choices.
WITH missing AS (
    SELECT i.installation_id, md5('lorkhan:global-stt:' || i.installation_id::text)::uuid AS configuration_id
    FROM lorkhan_internal.installations i
    WHERE NOT EXISTS (
        SELECT 1 FROM lorkhan_internal.configuration_sets c
        WHERE c.installation_id=i.installation_id AND c.kind='stt_provider' AND c.deleted_at IS NULL
    )
), inserted_sets AS (
    INSERT INTO lorkhan_internal.configuration_sets(configuration_id,installation_id,profile_id,kind,name,current_revision)
    SELECT configuration_id,installation_id,NULL,'stt_provider','Global STT Connector',1 FROM missing
    ON CONFLICT DO NOTHING
    RETURNING configuration_id,installation_id
), inserted_revisions AS (
    INSERT INTO lorkhan_internal.configuration_revisions(configuration_id,revision,content,change_reason)
    SELECT configuration_id,1,
        '{"driver":"deepgram","endpoint":"https://api.deepgram.com","model":"nova-3","voice":"","language":"en-US","timeout_ms":30000,"options":{}}'::jsonb,
        'Activate CHIM-compatible global STT default'
    FROM inserted_sets
    ON CONFLICT DO NOTHING
    RETURNING configuration_id
)
INSERT INTO lorkhan_internal.installation_provider_selections(installation_id,provider_kind,configuration_id)
SELECT c.installation_id,'stt_provider',c.configuration_id
FROM lorkhan_internal.configuration_sets c
JOIN inserted_revisions r ON r.configuration_id=c.configuration_id
WHERE NOT EXISTS (
        SELECT 1 FROM lorkhan_internal.installation_provider_selections s
    WHERE s.installation_id=c.installation_id AND s.provider_kind='stt_provider'
)
ON CONFLICT DO NOTHING;

-- Existing installations may already have an unselected STT connector; select the sole live record only.
INSERT INTO lorkhan_internal.installation_provider_selections(installation_id,provider_kind,configuration_id)
SELECT c.installation_id,'stt_provider',min(c.configuration_id::text)::uuid
FROM lorkhan_internal.configuration_sets c
WHERE c.kind='stt_provider' AND c.deleted_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM lorkhan_internal.installation_provider_selections s
      WHERE s.installation_id=c.installation_id AND s.provider_kind='stt_provider')
GROUP BY c.installation_id
HAVING count(*)=1
ON CONFLICT DO NOTHING;
