-- Older Quickstart versions can still use the saved endpoint as generic LM Studio.
UPDATE lorkhan_internal.quickstart_local_llm SET server_type='lm_studio' WHERE server_type='dwemerdistro';
ALTER TABLE lorkhan_internal.quickstart_local_llm DROP CONSTRAINT quickstart_local_llm_server_type_check;
ALTER TABLE lorkhan_internal.quickstart_local_llm ADD CONSTRAINT quickstart_local_llm_server_type_check CHECK (server_type IN ('lm_studio','ollama','llama_cpp','koboldcpp','other'));
