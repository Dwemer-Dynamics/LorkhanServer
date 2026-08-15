WITH ranked AS (
    SELECT document_id,
           row_number() OVER (
               PARTITION BY installation_id,
                            COALESCE(profile_id,'00000000-0000-0000-0000-000000000000'::uuid),
                            COALESCE(playthrough_id,'00000000-0000-0000-0000-000000000000'::uuid),
                            lower(topic)
               ORDER BY created_at DESC,document_id DESC
           ) AS duplicate_rank
    FROM almsivi_internal.knowledge_documents
    WHERE deleted_at IS NULL
      AND provenance->>'source' IS DISTINCT FROM 'factory-oghma'
)
UPDATE almsivi_internal.knowledge_documents AS document
SET deleted_at=clock_timestamp()
FROM ranked
WHERE ranked.document_id=document.document_id
  AND ranked.duplicate_rank>1;

CREATE UNIQUE INDEX IF NOT EXISTS knowledge_custom_topic_uq ON almsivi_internal.knowledge_documents (
    installation_id,
    COALESCE(profile_id,'00000000-0000-0000-0000-000000000000'::uuid),
    COALESCE(playthrough_id,'00000000-0000-0000-0000-000000000000'::uuid),
    lower(topic)
) WHERE deleted_at IS NULL
    AND provenance->>'source' IS DISTINCT FROM 'factory-oghma';
