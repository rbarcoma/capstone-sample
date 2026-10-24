export type NumericValue = number | string;

export type LandingAnalyticsContract = {
    asOf: string | null;
    trend: Array<{
        period: string;
        consumptionKwh: NumericValue;
        peakDemandKw: NumericValue;
    }>;
    comparison: Array<{
        period: string;
        actualKwh: NumericValue | null;
        predictedKwh: NumericValue | null;
    }>;
    peakDemand: Array<{
        period: string;
        peakDemandKw: NumericValue;
    }>;
    latestForecast: {
        period: string;
        predictedConsumptionKwh: NumericValue;
        changePercent: NumericValue | null;
        modelType: string;
        mae: NumericValue | null;
        rmse: NumericValue | null;
        r2Score: NumericValue | null;
    } | null;
    decisionSupport: {
        demandStatus: string;
        readinessLevel: string;
        basis?: {
            predictedConsumptionKwh: NumericValue;
            averageConsumptionKwh: NumericValue;
            peakDemandKw: NumericValue;
            solarIrradiance: NumericValue;
        };
    } | null;
};

export type LandingSnapshot = {
    asOf: string | null;
    comparison: Array<{
        period: string;
        actualKwh: number | null;
        predictedKwh: number | null;
    }>;
    decisionSupport: {
        demandStatus: string;
        readinessLevel: string;
        solarSignal: string;
    } | null;
    forecast: {
        period: string;
        predictedConsumptionKwh: number;
        changePercent: number | null;
        modelType: string;
        mae: number | null;
        rmse: number | null;
        r2Score: number | null;
    } | null;
    isIllustrative: boolean;
    peakDemand: Array<{
        period: string;
        peakDemandKw: number;
    }>;
    sourceLabel: string;
    trend: Array<{
        period: string;
        consumptionKwh: number;
        peakDemandKw: number;
    }>;
};

const illustrativeContract: LandingAnalyticsContract = {
    asOf: null,
    trend: [
        { period: 'Jan', consumptionKwh: 4180, peakDemandKw: 742 },
        { period: 'Feb', consumptionKwh: 4290, peakDemandKw: 756 },
        { period: 'Mar', consumptionKwh: 4410, peakDemandKw: 781 },
        { period: 'Apr', consumptionKwh: 4525, peakDemandKw: 804 },
        { period: 'May', consumptionKwh: 4470, peakDemandKw: 792 },
        { period: 'Jun', consumptionKwh: 4590, peakDemandKw: 818 },
        { period: 'Jul', consumptionKwh: 4680, peakDemandKw: 836 },
        { period: 'Aug', consumptionKwh: 4725, peakDemandKw: 844 },
        { period: 'Sep', consumptionKwh: 4640, peakDemandKw: 829 },
        { period: 'Oct', consumptionKwh: 4760, peakDemandKw: 852 },
        { period: 'Nov', consumptionKwh: 4815, peakDemandKw: 864 },
        { period: 'Dec', consumptionKwh: 4790, peakDemandKw: 858 },
    ],
    comparison: [
        { period: 'Jul', actualKwh: 4680, predictedKwh: 4610 },
        { period: 'Aug', actualKwh: 4725, predictedKwh: 4700 },
        { period: 'Sep', actualKwh: 4640, predictedKwh: 4695 },
        { period: 'Oct', actualKwh: 4760, predictedKwh: 4720 },
        { period: 'Nov', actualKwh: 4815, predictedKwh: 4785 },
        { period: 'Dec', actualKwh: 4790, predictedKwh: 4830 },
        { period: 'Next', actualKwh: null, predictedKwh: 4860 },
    ],
    peakDemand: [
        { period: 'Jul', peakDemandKw: 836 },
        { period: 'Aug', peakDemandKw: 844 },
        { period: 'Sep', peakDemandKw: 829 },
        { period: 'Oct', peakDemandKw: 852 },
        { period: 'Nov', peakDemandKw: 864 },
        { period: 'Dec', peakDemandKw: 858 },
    ],
    latestForecast: {
        period: 'Next month',
        predictedConsumptionKwh: 4860,
        changePercent: 1.5,
        modelType: 'Ridge (penalty 0.1)',
        mae: null,
        rmse: null,
        r2Score: null,
    },
    decisionSupport: {
        demandStatus: 'Moderate demand',
        readinessLevel: 'Developing readiness',
        basis: {
            predictedConsumptionKwh: 4860,
            averageConsumptionKwh: 4600,
            peakDemandKw: 858,
            solarIrradiance: 5.2,
        },
    },
};

function asFiniteNumber(value: NumericValue | null | undefined) {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    const parsed = Number(value);

    return Number.isFinite(parsed) ? parsed : null;
}

function normalizeContract(
    contract: LandingAnalyticsContract,
    isIllustrative: boolean,
): LandingSnapshot {
    const trend = contract.trend.flatMap((point) => {
        const consumptionKwh = asFiniteNumber(point.consumptionKwh);
        const peakDemandKw = asFiniteNumber(point.peakDemandKw);

        if (consumptionKwh === null || peakDemandKw === null) {
            return [];
        }

        return [{ period: point.period, consumptionKwh, peakDemandKw }];
    });
    const comparison = contract.comparison.flatMap((point) => {
        const actualKwh = asFiniteNumber(point.actualKwh);
        const predictedKwh = asFiniteNumber(point.predictedKwh);

        if (actualKwh === null && predictedKwh === null) {
            return [];
        }

        return [{ period: point.period, actualKwh, predictedKwh }];
    });
    const peakDemand = contract.peakDemand.flatMap((point) => {
        const peakDemandKw = asFiniteNumber(point.peakDemandKw);

        if (peakDemandKw === null) {
            return [];
        }

        return [{ period: point.period, peakDemandKw }];
    });
    const forecastValue = asFiniteNumber(
        contract.latestForecast?.predictedConsumptionKwh,
    );
    const forecast =
        contract.latestForecast && forecastValue !== null
            ? {
                  period: contract.latestForecast.period,
                  predictedConsumptionKwh: forecastValue,
                  changePercent: asFiniteNumber(
                      contract.latestForecast.changePercent,
                  ),
                  modelType: contract.latestForecast.modelType,
                  mae: asFiniteNumber(contract.latestForecast.mae),
                  rmse: asFiniteNumber(contract.latestForecast.rmse),
                  r2Score: asFiniteNumber(contract.latestForecast.r2Score),
              }
            : null;
    const solarIrradiance = asFiniteNumber(
        contract.decisionSupport?.basis?.solarIrradiance,
    );

    return {
        asOf: contract.asOf,
        comparison,
        decisionSupport: contract.decisionSupport
            ? {
                  demandStatus: contract.decisionSupport.demandStatus,
                  readinessLevel: contract.decisionSupport.readinessLevel,
                  solarSignal:
                      solarIrradiance === null
                          ? 'Input not available'
                          : isIllustrative
                            ? 'Favorable sample signal'
                            : `${solarIrradiance.toLocaleString('en-US', { maximumFractionDigits: 2 })} irradiance input`,
              }
            : null,
        forecast,
        isIllustrative,
        peakDemand,
        sourceLabel: isIllustrative
            ? 'Illustrative sample data — not verified government results'
            : contract.asOf
              ? `Connected system data as of ${contract.asOf}`
              : 'Connected system data',
        trend,
    };
}

export function resolveLandingSnapshot(
    contract?: LandingAnalyticsContract,
): LandingSnapshot {
    return normalizeContract(
        contract ?? illustrativeContract,
        contract === undefined,
    );
}

export const heroPreviewValues = illustrativeContract.trend.map((point) => ({
    period: point.period,
    value: Number(point.consumptionKwh),
}));
