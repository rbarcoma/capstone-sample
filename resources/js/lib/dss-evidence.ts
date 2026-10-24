import type { DssResult } from '@/types';

/** Presentation only: never replace saved labels, advice or assessment facts. */
export function legacyDssNotice(dss?: Pick<DssResult, 'basis'> | null): string {
    return dss && dss.basis?.rule_version !== 'dss_evidence_qualification_v1'
        ? 'Historical / unqualified evidence. '
        : '';
}
