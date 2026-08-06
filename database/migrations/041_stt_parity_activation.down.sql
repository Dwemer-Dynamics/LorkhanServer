-- Remove only untouched migration-owned defaults. User-revised connectors are preserved.
DELETE FROM almsivi_internal.installation_provider_selections s
USING almsivi_internal.configuration_sets c, almsivi_internal.configuration_revisions r
WHERE s.configuration_id=c.configuration_id AND s.provider_kind='stt_provider'
  AND r.configuration_id=c.configuration_id AND r.revision=1
  AND c.name='Global STT Connector' AND c.current_revision=1
  AND r.change_reason='Activate CHIM-compatible global STT default';

DELETE FROM almsivi_internal.configuration_sets c
USING almsivi_internal.configuration_revisions r
WHERE r.configuration_id=c.configuration_id AND r.revision=1
  AND c.name='Global STT Connector' AND c.current_revision=1
  AND r.change_reason='Activate CHIM-compatible global STT default';
