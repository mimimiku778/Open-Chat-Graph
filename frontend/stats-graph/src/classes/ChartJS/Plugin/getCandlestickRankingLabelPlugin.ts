import OpenChatChart from "../../OpenChatChart"

export default function getCandlestickRankingLabelPlugin(ocChart: OpenChatChart) {
  return {
    id: 'candlestick-ranking-labels',
    afterDatasetsDraw(chart: any) {
      if (ocChart.getMode() !== 'candlestick' || !ocChart.ohlcRankingData.length) return

      const meta = chart.getDatasetMeta(1)
      if (!meta?.data?.length) return

      const chartArea = chart.chartArea
      const range = chart.scales.x.max - chart.scales.x.min + 1
      const showAll = range < 9
      const isAllPeriod = ocChart.limit === 0
      const dataLen = meta.data.length

      const isLimit8 = ocChart.limit === 8
      const fontSize = isLimit8
        ? (ocChart.isPC ? 10.5 : (ocChart.isMiniMobile ? 9 : 10))
        : ocChart.limit === 31 ? 9
        : (ocChart.isPC ? 9.5 : 9)

      const ctx = chart.ctx
      ctx.save()
      ctx.font = `${fontSize}px system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif`
      ctx.fillStyle = '#555'
      ctx.textAlign = 'center'
      ctx.textBaseline = 'bottom'

      const y = chartArea.bottom - 2
      const gap = 4
      let lastRight = -Infinity

      for (let i = 0; i < dataLen; i++) {
        // 週: 全件表示、全期間: autoSkip付き全件、月: 先頭と末尾のみ
        if (!showAll && !isAllPeriod && i !== 0 && i !== dataLen - 1) continue

        const el = meta.data[i]
        const raw = ocChart.ohlcRankingData[i]
        if (!el || raw?.h == null) continue

        const text = String(raw.h)
        const halfWidth = ctx.measureText(text).width / 2
        const left = el.x - halfWidth

        if (left < lastRight + gap && i !== 0 && i !== dataLen - 1) continue

        ctx.fillText(text, el.x, y)
        lastRight = el.x + halfWidth
      }

      ctx.restore()
    },
  }
}
