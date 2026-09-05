-- Remove only untouched migration-owned defaults. User-revised connectors are preserved.
DELETE FROM lorkhan_internal.installation_provider_selections s
USING lorkhan_internal.configuration_sets c, lorkhan_internal.configuration_revisions r
WHERE s.configuration_id=c.configuration_id AND s.provider_kind='stt_provider'
  AND r.configuration_id=c.configuration_id AND r.revision=1
  AND c.name='Global STT Connector' AND c.current_revision=1
  AND r.change_reason='Activate CHIM-compatible global STT default';

DELETE FROM lorkhan_internal.configuration_sets c
USING lorkhan_internal.configuration_revisions r
WHERE r.configuration_id=c.configuration_id AND r.revision=1
  AND c.name='Global STT Connector' AND c.current_revision=1
  AND r.change_reason='Activate CHIM-compatible global STT default';
