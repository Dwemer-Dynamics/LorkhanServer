# Oghma behavioral parity contract

Contract version: `oghma-parity-v1`.

ALMSIVIserver owns this implementation. It has no HerikaServer runtime, package, submodule, include,
Composer, or deployment dependency. Both servers validate the same observable contract while retaining
native implementations. Morrowind catalog content, races, regions, cells, content files, and record
identities remain ALMSIVI-specific.

## Eligible requests

Oghma runs only for typed text, STT transcripts, and playback-driven rechat or equivalent explicit
text request families. Direct action-menu turns, unknown sources, autonomy sources, malformed inputs,
and non-text/STT inputs are ineligible. The larger cross-product fixture suite is maintained separately
from the product repository.

## Grounding order

1. Normalize Unicode text, punctuation, whitespace, and compact spellings.
2. Match an unambiguous canonical topic.
3. Match an unambiguous alias.
4. Match an unambiguous compact canonical topic or alias.
5. Apply bounded phonetic/edit-distance recovery only with sufficient transcript or conversational evidence.
6. Reject speaker labels, weak single-word senses, background mentions, ambiguous neighbors, and wrong-sense homonyms.
7. Rank repeated salient mentions, then preserve conversational mention order up to one to three topics.
8. Only when all entity grounding abstains, evaluate exact multiword tags.
9. Only when local grounding still abstains and the player made an explicit knowledge request, allow one bounded inherited connector fallback. Suggestions must resolve to one canonical topic or unambiguous alias/compact identity. Tags are not connector identities.

Canonical and alias grounding always takes precedence over tag fallback. Tags are never added to the
topic/alias identity lexicon and are never fuzzily matched.

## Guarded tag fallback

- Ignore one-word tags and tags identical to canonical topics or aliases.
- Prefer longer overlapping exact tag phrases, including when the longer phrase is rejected.
- A unique multiword tag owned by exactly one article may select that article.
- A tag owned by two or three articles cannot select an article by itself.
- Two distinct shared tag phrases that corroborate the same article may select it only during an explicit knowledge request.
- Tags owned by more than three articles, corrupted tags, ambiguous support, and tag-only speaker labels abstain.
- Every evaluated tag selection or rejection is retained in the retrieval trace.

## Settings and routing

The master enable switch, connector-fallback switch, topic count, result limit, race injection,
location injection, and fallback timeout use Global -> Core Profile -> NPC inheritance. Knowledge
classes and the Oghma connector route use the same precedence. The connector route falls back to the
inherited Fast connector only for profiles created before the dedicated route field existed. Disabling
connector fallback does not disable local deterministic grounding. Disabling the master switch disables
grounding, forced context, connector fallback, and prompt injection.

## Context and access

Conversation topics consume the configured topic slots. Target/nearby race and current cell, region,
or named-location signals are separately configured and do not consume those slots. Morrowind race
aliases canonicalize to Dunmer, Altmer, Bosmer, and Orsimer where applicable.

`knowall` or an allowed advanced class selects `topic_desc`. Otherwise an allowed basic class selects
`topic_desc_basic`. A matching negative `!class` denies that tier. Any selected conversational,
race, or location topic that is not authorized remains in the prompt as a structured denial.

`common` and `esoteric` are article-only markers, never NPC permissions. After negative exclusions,
a basic `common` article is available to every NPC, including one with no assigned knowledge tags.
`common` is invalid for advanced access. Hidden and esoteric articles omit `common` and continue to
require their specialist classes. Empty legacy or custom article classes remain unrestricted, and
`knowall` remains the explicit advanced override.

Both products use the frozen lowercase snake-case vocabulary for shared roles, races, cultures, and
organizations. Legacy class IDs normalize at runtime and during catalog revision. `mages_guild` and
`college_of_winterhold` are explicitly different organizations and never normalize to one another;
`fighters_guild` and `companions` are likewise analogous but distinct. `common` is basic access
only, and an `esoteric` basic classification
supersedes `common` on the same article.

## Prompt fragment

Authorized and denied knowledge uses this UTF-8 XML shape inside the existing Morrowind context:

```xml
<oghma contract="oghma-parity-v1" status="grounded">
  <article topic="red_mountain" source="conversation" access="advanced">
    <content>Canonical article text.</content>
  </article>
  <article topic="sixth_house" source="conversation" access="denied">
    <denial reason="knowledge_classes_not_authorized" />
  </article>
</oghma>
```

Element text is XML-escaped without lossy Unicode replacement. Article and denied-topic order follows
the resolved signal order and configured result limit.

## Trace contract

Knowledge retrieval uses algorithm `oghma-parity-v1`. Current statuses are `grounded`, `no_match`,
`fallback_succeeded`, `fallback_unresolved`, `fallback_failed`, `fallback_disabled`,
`fallback_unconfigured`, `disabled`, `ineligible`, and `unavailable`; `not_run` and `legacy` are
read-only compatibility states. The trace records eligibility, master state, limits, canonical or tag
matches, rejections, tag decisions, connector suggestions, fallback eligibility, race/location
signals, access decisions, denied topics, result IDs, scores, settings sources, and turn correlation.

## Catalog and exclusions

Factory catalogs remain immutable, versioned, checksum-verified, atomically activated, and
rollback-capable. Installation custom articles remain separate and override-safe. Dynamic Oghma,
timer autonomy, Background Life, and ITT are outside this contract and remain excluded.

The separate retrieval harness validates the frozen 550-case Morrowind suite plus 69 parity fixtures.
Its deterministic local retrieval p95 gate is 50 ms for the 1,300-article v5 catalog. Database, browser,
WSL, and in-game evidence remain separate acceptance layers.
