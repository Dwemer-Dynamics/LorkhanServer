-- Recovery state is derived from provider_attempts; dropping its lookup index loses no data.
DROP INDEX lorkhan_internal.provider_attempts_turn_connector_recovery;
