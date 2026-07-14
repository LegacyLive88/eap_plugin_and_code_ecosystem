<?php

/**
 * @copyright © Melograno Ventures. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace AmeliaBooking\Infrastructure\Services\Report\Spout;

use AmeliaBooking\Domain\Services\Report\ReportServiceInterface;
use OpenSpout\Common\Exception\IOException;
use OpenSpout\Writer\Common\Creator\WriterEntityFactory;
use OpenSpout\Writer\Exception\WriterNotOpenedException;

class CsvService implements ReportServiceInterface
{
    /**
     * @throws IOException
     * @throws WriterNotOpenedException
     */
    public function generateReport(array $rows, string $name, string $delimiter): void
    {
        $writer = WriterEntityFactory::createCSVWriter();
        $writer->setFieldDelimiter($delimiter);
        $writer->openToBrowser($name . '.csv');

        if (!$rows) {
            $writer->close();

            return;
        }

        $writer->addRow(WriterEntityFactory::createRowFromArray(array_keys($rows[0])));

        foreach ($rows as $row) {
            $writer->addRow(WriterEntityFactory::createRowFromArray($row));
        }

        $writer->close();
    }
}
