import { ChartConfiguration } from 'chart.js/auto'
import OpenChatChart from '../../OpenChatChart'
import getDataLabelBarCallback from '../Callback/getDataLabelBarCallback'
import getLineGradient from '../Callback/getLineGradient'
import getLineGradientBar from '../Callback/getLineGradientBar'
import getPointRadiusCallback from '../Callback/getPointRadiusCallback'
import getDataLabelLineCallback from '../Callback/getDataLabelLineCallback'
import { t } from '../../../util/translation'

export const lineEasing = 'easeOutQuart'
export const barEasing = 'easeOutCirc'

export default function buildData(ocChart: OpenChatChart) {
  if (ocChart.getMode() === 'candlestick') {
    const candleDatalabelDisplay = (context: any) => {
      const range = context.chart.scales.x.max - context.chart.scales.x.min + 1
      if (range < 9) return true
      const len = context.dataset.data.length
      const index = context.dataIndex
      return index === 0 || index === len - 1 ? 'auto' : false
    }

    const isLimit8 = ocChart.limit === 8
    const candleLabelFontSize = isLimit8
      ? (ocChart.isPC ? 10.5 : (ocChart.isMiniMobile ? 9 : 10))
      : ocChart.limit === 31 ? 9
      : (ocChart.isPC ? 9.5 : 9)
    const candleLabelFont = { weight: 'normal' as const, size: candleLabelFontSize }

    const datasets: any[] = [{
      type: 'candlestick' as any,
      label: t('メンバー数'),
      data: ocChart.ohlcData,
      color: { up: '#00c853', down: '#ff1744', unchanged: '#757575' },
      backgroundColors: { up: 'rgba(0, 200, 83, 0.7)', down: 'rgba(255, 23, 68, 0.7)', unchanged: 'rgba(117, 117, 117, 0.7)' },
      borderColors: { up: '#00c853', down: '#ff1744', unchanged: '#757575' },
      datalabels: {
        display: candleDatalabelDisplay,
        formatter: (v: any) => v?.c?.toLocaleString() ?? '',
        align: 'end' as const,
        anchor: 'end' as const,
        font: candleLabelFont,
      },
      animation: { duration: ocChart.animation ? undefined : 0 },
      yAxisID: 'rainChart',
    }]

    if (ocChart.ohlcRankingData.length) {
      datasets.push({
        type: 'candlestick' as any,
        label: `${ocChart.option.label2} | ${ocChart.option.category}`,
        data: ocChart.ohlcRankingData,
        color: { up: 'rgba(255, 109, 0, 0.35)', down: 'rgba(41, 121, 255, 0.35)', unchanged: 'rgba(158, 158, 158, 0.35)' },
        backgroundColors: { up: 'rgba(255, 109, 0, 0.1)', down: 'rgba(41, 121, 255, 0.1)', unchanged: 'rgba(158, 158, 158, 0.1)' },
        borderColors: { up: 'rgba(255, 109, 0, 0.35)', down: 'rgba(41, 121, 255, 0.35)', unchanged: 'rgba(158, 158, 158, 0.35)' },
        borderWidth: 1,
        datalabels: { display: false },
        animation: { duration: ocChart.animation ? undefined : 0 },
        yAxisID: 'temperatureChart',
      })
    }

    return { labels: ocChart.data.date, datasets }
  }

  const firstIndex = ocChart.data.graph1.findIndex((v) => !!v)
  const lastIndex =
    ocChart.data.graph1.length -
    1 -
    ocChart.data.graph1
      .slice()
      .reverse()
      .findIndex((v) => !!v)

  const data: ChartConfiguration<'bar' | 'line', (number | null)[], string | string[]>['data'] = {
    labels: ocChart.data.date,
    datasets: [
      {
        type: 'line',
        label: ocChart.option.label1,
        data: ocChart.data.graph1,
        pointRadius: getPointRadiusCallback(firstIndex, lastIndex),
        fill: false,
        backgroundColor: 'rgba(0,0,0,0)',
        borderColor: function (context) {
          const chart = context.chart
          const { ctx, chartArea } = chart

          if (!chartArea) {
            // This case happens on initial chart load
            return
          }
          return getLineGradient(ctx, chartArea)
        },
        borderWidth: 3,
        spanGaps: true,
        pointBackgroundColor: '#fff',
        /* @ts-ignore */
        lineTension: 0.4,
        datalabels: {
          display: getDataLabelLineCallback(firstIndex, lastIndex),
          align: 'end',
          anchor: 'end',
        },
        animation: {
          duration: ocChart.animation ? undefined : 0,
        },
        yAxisID: 'rainChart',
      },
    ],
  }

  if (ocChart.data.graph2.length) {
    data.datasets.push({
      type: 'bar',
      label: `${ocChart.option.label2} | ${ocChart.option.category}`,
      data: ocChart.getReverseGraph2(ocChart.data.graph2),
      backgroundColor: function (context) {
        const chart = context.chart
        const { ctx, chartArea } = chart

        if (!chartArea) {
          // This case happens on initial chart load
          return
        }
        return getLineGradientBar(ctx, chartArea)
      },
      barPercentage: ocChart.limit === 8 ? 0.77 : 0.9,
      borderRadius: ocChart.limit === 8 || ocChart.data.date.length < 10 ? 4 : 2,
      datalabels: {
        align: 'start',
        anchor: 'start',
        formatter: (v) => {
          if (v === null) return ''
          return v ? ocChart.graph2Max - v + 1 : t('圏外')
        },
        display: getDataLabelBarCallback(ocChart),
      },
      yAxisID: 'temperatureChart',
    })
  }

  return data
}
