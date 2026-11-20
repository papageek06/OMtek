import { colorLabel, type MailTonerReading } from '../domain/imprimantes/rules'
import { formatDateTime } from './formatters/date'
import './MailTonerReports.css'

export default function MailTonerReports({ readings, heading: Heading }: {
  readings: MailTonerReading[]
  heading: 'h3' | 'h4'
}) {
  if (readings.length === 0) return null

  return (
    <div className="mail-toner-reports">
      <Heading>Changements de cartouche signalés par mail</Heading>
      <ul>
        {readings.map((reading) => (
          <li key={reading.id}>
            <time dateTime={reading.reportedAt}>{formatDateTime(reading.reportedAt)}</time>
            <span>
              {colorLabel(reading.color)} · {reading.level === null ? 'Niveau non communiqué' : `${reading.level} %`}
            </span>
          </li>
        ))}
      </ul>
    </div>
  )
}
