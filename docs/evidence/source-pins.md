# Frozen ALMSIVI parity source pins

Recorded and user-confirmed: 2026-08-09.

These refs are the immutable implementation baseline for the paired CHIM/Dialectic parity goal:

- ALMSIVI checkpoint: `eee848c00bb48536c67de97ab953760e7c67da76`
- ALMSIVIserver checkpoint: `554befb6d167d2d6deb436662de858503853a584`
- CHIM `origin/unstable`: `005df4c1fda5ff195dc14a674fe71b11542be4df`
- HerikaServer `origin/unstable`: `c973f5c8fde2d01cb8211be3d5f96d1783663da4`
- Dialectic `origin/unstable`: `5cd2817a6733acbe25ca21bdfb716ed64617f5f8`
- DialecticServer `origin/unstable`: `4f3d8fed834b283fd53ff0655ddd091d849dea1d`
- OpenMW 0.51.0: `f4bec41444214a7903bebd178389ca22ca13f646`, Lua API revision 129

Do not silently advance these refs during implementation. Perform one deliberate upstream parity
refresh only after the frozen plan is complete. External source is used only according to the
presentation, behavior, contract, licensing, and provenance rules in the paired integration plans.
