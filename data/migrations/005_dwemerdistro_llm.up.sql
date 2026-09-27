-- Permit the managed WSL engine while retaining every existing local provider.
ALTER TABLE lorkhan_internal.quickstart_local_llm DROP CONSTRAINT quickstart_local_llm_server_type_check;
ALTER TABLE lorkhan_internal.quickstart_local_llm ADD CONSTRAINT quickstart_local_llm_server_type_check CHECK (server_type IN ('dwemerdistro','lm_studio','ollama','llama_cpp','koboldcpp','other'));
