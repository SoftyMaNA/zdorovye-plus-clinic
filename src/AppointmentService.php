<?php
declare(strict_types=1);

final class AppointmentService
{
    public function __construct(private PDO $db) {}

    public function book(int $patient, int $doctor, string $start, string $end): int
    {
        $a = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $start);
        $b = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $end);
        if (!$a || !$b || $a->format('Y-m-d H:i:s') !== $start
            || $b->format('Y-m-d H:i:s') !== $end || $b <= $a) {
            throw new DomainException('Некорректный интервал приёма');
        }
        $this->db->beginTransaction();
        try {
            // Все операции записи используют одинаковый порядок блокировок.
            $q = $this->db->prepare('SELECT active FROM patients WHERE id=? FOR UPDATE');
            $q->execute([$patient]);
            if ((int)$q->fetchColumn() !== 1) {
                throw new DomainException('Пациент не найден или неактивен');
            }
            $q = $this->db->prepare('SELECT active FROM doctors WHERE id=? FOR UPDATE');
            $q->execute([$doctor]);
            if ((int)$q->fetchColumn() !== 1) {
                throw new DomainException('Врач недоступен');
            }
            $q = $this->db->prepare("SELECT id FROM appointments
                WHERE status='scheduled' AND (doctor_id=? OR patient_id=?)
                AND starts_at < ? AND ends_at > ? LIMIT 1 FOR UPDATE");
            $q->execute([$doctor, $patient, $end, $start]);
            if ($q->fetchColumn() !== false) {
                throw new DomainException('Врач или пациент уже занят в это время');
            }
            $q = $this->db->prepare('INSERT INTO appointments(patient_id,doctor_id,starts_at,ends_at) VALUES(?,?,?,?)');
            $q->execute([$patient,$doctor,$start,$end]);
            $id = (int)$this->db->lastInsertId();
            $q = $this->db->prepare("INSERT INTO audit_log(appointment_id,event_type) VALUES(?,'created')");
            $q->execute([$id]);
            $this->db->commit();
            return $id;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function cancel(int $id): void
    {
        $this->db->beginTransaction();
        try {
            $q = $this->db->prepare("UPDATE appointments SET status='cancelled' WHERE id=? AND status='scheduled'");
            $q->execute([$id]);
            if ($q->rowCount() !== 1) throw new DomainException('Активная запись не найдена');
            $q = $this->db->prepare("INSERT INTO audit_log(appointment_id,event_type) VALUES(?,'cancelled')");
            $q->execute([$id]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }
}
