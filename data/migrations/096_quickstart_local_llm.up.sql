CREATE TABLE lorkhan_internal.quickstart_local_llm (
    installation_id uuid PRIMARY KEY REFERENCES lorkhan_internal.installations(installation_id),
    configuration_id uuid NOT NULL,
    server_type text NOT NULL CHECK (server_type IN ('lm_studio','ollama','llama_cpp','koboldcpp','other')),
    scope text NOT NULL CHECK (scope IN ('conversations','all')),
    FOREIGN KEY (configuration_id,installation_id) REFERENCES lorkhan_internal.configuration_sets(configuration_id,installation_id)
);
