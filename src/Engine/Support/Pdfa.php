<?php

declare(strict_types=1);

namespace FlexPDF\Engine\Support;

/**
 * What an archival document carries that an ordinary one does not.
 *
 * The target is PDF/A-3b, ISO 19005-3 level B: the file renders the same way
 * on any reader, forever, with everything it needs inside it. Level B is
 * about appearance; level A adds the structure tree, which this engine writes
 * as well and which is why `pdfa()` turns tagging on.
 *
 * Three things a conforming file needs and an ordinary one does not, all of
 * them held here rather than scattered through the writer: an ICC profile for
 * the output intent, an XMP packet saying which part and level the file
 * claims, and the two refusals PDF/A forces on a writer that cannot meet it.
 */
final class Pdfa
{
    /** ISO 19005-3, which is the part number the XMP declares. */
    public const string PART = '3';

    /** Level B, which is what a document claims unless it asks for level A. */
    public const string CONFORMANCE = 'B';

    /** ISO 14289-1, which is the part number a PDF/UA claim declares. */
    public const string UA_PART = '1';

    /** The PDF version an ISO 19005-3 file is written against. */
    public const string VERSION = '1.7';

    /**
     * sRGB IEC61966-2.1, as an ICC v2.1 matrix/TRC display profile, 2,580
     * bytes.
     *
     * PDF/A wants a color space that does not depend on the device and every
     * color this engine writes is DeviceRGB, so a conforming file has to name
     * an RGB destination profile. This one is built from the published numbers
     * in IEC 61966-2-1 rather than copied from anyone: the D50 adapted
     * primaries, the Bradford adaptation matrix and the transfer function.
     * `docs/harness/make-srgb-icc.py` is what made it and it is deterministic,
     * so the bytes below can be re-derived and compared.
     *
     * Checked two ways: littleCMS reads it and a 0..255 ramp round-tripped
     * through it against littleCMS's own sRGB is identical on all three
     * channels, and veraPDF accepts the output intent built from it.
     *
     * It is a constant rather than a file so the engine reads nothing new at
     * render time and no packaging rule has to be right for it to be there.
     */
    private const string SRGB_BASE64 =
        'AAAKFAAAAAACEAAAbW50clJHQiBYWVogB+oAAQABAAAAAAAAYWNzcAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'
        . 'APbWAAEAAAAA0y0AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAKZGVzYwAA'
        . 'APwAAABsd3RwdAAAAWgAAAAUclhZWgAAAXwAAAAUZ1hZWgAAAZAAAAAUYlhZWgAAAaQAAAAUY2hhZAAAAbgAAAAsclRS'
        . 'QwAAAeQAAAgMZ1RSQwAAAeQAAAgMYlRSQwAAAeQAAAgMY3BydAAACfAAAAAhZGVzYwAAAAAAAAASc1JHQiBJRUM2MTk2'
        . 'Ni0yLjEAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'
        . 'AAAAAAAAAAAAAAAAAAAAWFlaIAAAAAAAAPbWAAEAAAAA0y1YWVogAAAAAAAAb6IAADj1AAADkFhZWiAAAAAAAABimQAA'
        . 't4UAABjaWFlaIAAAAAAAACSgAAAPhAAAts9zZjMyAAAAAAABDEIAAAXe///zJgAAB5MAAP2Q///7ov///aMAAAPcAADA'
        . 'bmN1cnYAAAAAAAAEAAAAAAUACgAPABQAGQAeACMAKAAtADIANwA7AEAARQBKAE8AVABZAF4AYwBoAG0AcgB3AHwAgQCG'
        . 'AIsAkACVAJoAnwCkAKkArgCyALcAvADBAMYAywDQANUA2wDgAOUA6wDwAPYA+wEBAQcBDQETARkBHwElASsBMgE4AT4B'
        . 'RQFMAVIBWQFgAWcBbgF1AXwBgwGLAZIBmgGhAakBsQG5AcEByQHRAdkB4QHpAfIB+gIDAgwCFAIdAiYCLwI4AkECSwJU'
        . 'Al0CZwJxAnoChAKOApgCogKsArYCwQLLAtUC4ALrAvUDAAMLAxYDIQMtAzgDQwNPA1oDZgNyA34DigOWA6IDrgO6A8cD'
        . '0wPgA+wD+QQGBBMEIAQtBDsESARVBGMEcQR+BIwEmgSoBLYExATTBOEE8AT+BQ0FHAUrBToFSQVYBWcFdwWGBZYFpgW1'
        . 'BcUF1QXlBfYGBgYWBicGNwZIBlkGagZ7BowGnQavBsAG0QbjBvUHBwcZBysHPQdPB2EHdAeGB5kHrAe/B9IH5Qf4CAsI'
        . 'HwgyCEYIWghuCIIIlgiqCL4I0gjnCPsJEAklCToJTwlkCXkJjwmkCboJzwnlCfsKEQonCj0KVApqCoEKmAquCsUK3Arz'
        . 'CwsLIgs5C1ELaQuAC5gLsAvIC+EL+QwSDCoMQwxcDHUMjgynDMAM2QzzDQ0NJg1ADVoNdA2ODakNww3eDfgOEw4uDkkO'
        . 'ZA5/DpsOtg7SDu4PCQ8lD0EPXg96D5YPsw/PD+wQCRAmEEMQYRB+EJsQuRDXEPURExExEU8RbRGMEaoRyRHoEgcSJhJF'
        . 'EmQShBKjEsMS4xMDEyMTQxNjE4MTpBPFE+UUBhQnFEkUahSLFK0UzhTwFRIVNBVWFXgVmxW9FeAWAxYmFkkWbBaPFrIW'
        . '1hb6Fx0XQRdlF4kXrhfSF/cYGxhAGGUYihivGNUY+hkgGUUZaxmRGbcZ3RoEGioaURp3Gp4axRrsGxQbOxtjG4obshva'
        . 'HAIcKhxSHHscoxzMHPUdHh1HHXAdmR3DHeweFh5AHmoelB6+HukfEx8+H2kflB+/H+ogFSBBIGwgmCDEIPAhHCFIIXUh'
        . 'oSHOIfsiJyJVIoIiryLdIwojOCNmI5QjwiPwJB8kTSR8JKsk2iUJJTglaCWXJccl9yYnJlcmhya3JugnGCdJJ3onqyfc'
        . 'KA0oPyhxKKIo1CkGKTgpaymdKdAqAio1KmgqmyrPKwIrNitpK50r0SwFLDksbiyiLNctDC1BLXYtqy3hLhYuTC6CLrcu'
        . '7i8kL1ovkS/HL/4wNTBsMKQw2zESMUoxgjG6MfIyKjJjMpsy1DMNM0YzfzO4M/E0KzRlNJ402DUTNU01hzXCNf02NzZy'
        . 'Nq426TckN2A3nDfXOBQ4UDiMOMg5BTlCOX85vDn5OjY6dDqyOu87LTtrO6o76DwnPGU8pDzjPSI9YT2hPeA+ID5gPqA+'
        . '4D8hP2E/oj/iQCNAZECmQOdBKUFqQaxB7kIwQnJCtUL3QzpDfUPARANER0SKRM5FEkVVRZpF3kYiRmdGq0bwRzVHe0fA'
        . 'SAVIS0iRSNdJHUljSalJ8Eo3Sn1KxEsMS1NLmkviTCpMcky6TQJNSk2TTdxOJU5uTrdPAE9JT5NP3VAnUHFQu1EGUVBR'
        . 'm1HmUjFSfFLHUxNTX1OqU/ZUQlSPVNtVKFV1VcJWD1ZcVqlW91dEV5JX4FgvWH1Yy1kaWWlZuFoHWlZaplr1W0VblVvl'
        . 'XDVchlzWXSddeF3JXhpebF69Xw9fYV+zYAVgV2CqYPxhT2GiYfViSWKcYvBjQ2OXY+tkQGSUZOllPWWSZedmPWaSZuhn'
        . 'PWeTZ+loP2iWaOxpQ2maafFqSGqfavdrT2una/9sV2yvbQhtYG25bhJua27Ebx5veG/RcCtwhnDgcTpxlXHwcktypnMB'
        . 'c11zuHQUdHB0zHUodYV14XY+dpt2+HdWd7N4EXhueMx5KnmJeed6RnqlewR7Y3vCfCF8gXzhfUF9oX4BfmJ+wn8jf4R/'
        . '5YBHgKiBCoFrgc2CMIKSgvSDV4O6hB2EgITjhUeFq4YOhnKG14c7h5+IBIhpiM6JM4mZif6KZIrKizCLlov8jGOMyo0x'
        . 'jZiN/45mjs6PNo+ekAaQbpDWkT+RqJIRknqS45NNk7aUIJSKlPSVX5XJljSWn5cKl3WX4JhMmLiZJJmQmfyaaJrVm0Kb'
        . 'r5wcnImc951kndKeQJ6unx2fi5/6oGmg2KFHobaiJqKWowajdqPmpFakx6U4pammGqaLpv2nbqfgqFKoxKk3qamqHKqP'
        . 'qwKrdavprFys0K1ErbiuLa6hrxavi7AAsHWw6rFgsdayS7LCszizrrQltJy1E7WKtgG2ebbwt2i34LhZuNG5SrnCuju6'
        . 'tbsuu6e8IbybvRW9j74KvoS+/796v/XAcMDswWfB48JfwtvDWMPUxFHEzsVLxcjGRsbDx0HHv8g9yLzJOsm5yjjKt8s2'
        . 'y7bMNcy1zTXNtc42zrbPN8+40DnQutE80b7SP9LB00TTxtRJ1MvVTtXR1lXW2Ndc1+DYZNjo2WzZ8dp22vvbgNwF3Ird'
        . 'EN2W3hzeot8p36/gNuC94UThzOJT4tvjY+Pr5HPk/OWE5g3mlucf56noMui86Ubp0Opb6uXrcOv77IbtEe2c7ijutO9A'
        . '78zwWPDl8XLx//KM8xnzp/Q09ML1UPXe9m32+/eK+Bn4qPk4+cf6V/rn+3f8B/yY/Sn9uv5L/tz/bf//dGV4dAAAAABO'
        . 'byBjb3B5cmlnaHQsIHVzZSBmcmVlbHkAAAAA';

    /** The profile's bytes. */
    public static function iccProfile(): string
    {
        return (string) base64_decode(self::SRGB_BASE64, true);
    }

    /**
     * Which XMP property each /Info entry has to be repeated as.
     *
     * ISO 19005-3 asks for more than a metadata stream being present: an entry
     * in the document information dictionary that has an analogous XMP
     * property has to appear there too, with the same value. So this table is
     * not a convenience, it is the requirement, and a key missing from it
     * would be a /Info entry the file contradicts itself about.
     *
     * The two-element value is the namespace prefix and the property name; the
     * third says how the value is wrapped, since Dublin Core spells a title as
     * a language alternative and an author as an ordered list.
     *
     * @var array<string, array{0:string,1:string,2:string}>
     */
    private const array XMP_KEYS = [
        'title'        => ['dc', 'title', 'alt'],
        'author'       => ['dc', 'creator', 'seq'],
        'subject'      => ['dc', 'description', 'alt'],
        'keywords'     => ['pdf', 'Keywords', 'plain'],
        'producer'     => ['pdf', 'Producer', 'plain'],
        'creator'      => ['xmp', 'CreatorTool', 'plain'],
        'creationdate' => ['xmp', 'CreateDate', 'date'],
        'moddate'      => ['xmp', 'ModifyDate', 'date'],
    ];

    private const array NAMESPACES = [
        'dc'      => 'http://purl.org/dc/elements/1.1/',
        'pdf'     => 'http://ns.adobe.com/pdf/1.3/',
        'xmp'     => 'http://ns.adobe.com/xap/1.0/',
        'pdfaid'  => 'http://www.aiim.org/pdfa/ns/id/',
        'pdfuaid' => 'http://www.aiim.org/pdfua/ns/id/',
    ];

    /**
     * What a PDF/A file has to say before it may carry a `pdfuaid:part`.
     *
     * ISO 19005-3 clause 6.6.2.3.2 lets an XMP packet use a property outside
     * the predefined schemas **only** if the packet describes that schema
     * itself, so a file claiming both PDF/A and PDF/UA has to declare the
     * accessibility namespace here or the archival claim fails on the very
     * property that makes the other claim. veraPDF's `3a` profile is what said
     * so: the UA identification alone takes a passing file to 153 rules of 155,
     * and the two failures are this schema missing.
     */
    private const string UA_EXTENSION_SCHEMA =
        "   <rdf:Description rdf:about=\"\" xmlns:pdfaExtension=\"http://www.aiim.org/pdfa/ns/extension/\""
        . " xmlns:pdfaSchema=\"http://www.aiim.org/pdfa/ns/schema#\""
        . " xmlns:pdfaProperty=\"http://www.aiim.org/pdfa/ns/property#\">\n"
        . "    <pdfaExtension:schemas>\n"
        . "     <rdf:Bag>\n"
        . "      <rdf:li rdf:parseType=\"Resource\">\n"
        . "       <pdfaSchema:schema>PDF/UA Universal Accessibility Schema</pdfaSchema:schema>\n"
        . "       <pdfaSchema:namespaceURI>http://www.aiim.org/pdfua/ns/id/</pdfaSchema:namespaceURI>\n"
        . "       <pdfaSchema:prefix>pdfuaid</pdfaSchema:prefix>\n"
        . "       <pdfaSchema:property>\n"
        . "        <rdf:Seq>\n"
        . "         <rdf:li rdf:parseType=\"Resource\">\n"
        . "          <pdfaProperty:name>part</pdfaProperty:name>\n"
        . "          <pdfaProperty:valueType>Integer</pdfaProperty:valueType>\n"
        . "          <pdfaProperty:category>internal</pdfaProperty:category>\n"
        . "          <pdfaProperty:description>Which part of ISO 14289 the file follows</pdfaProperty:description>\n"
        . "         </rdf:li>\n"
        . "        </rdf:Seq>\n"
        . "       </pdfaSchema:property>\n"
        . "      </rdf:li>\n"
        . "     </rdf:Bag>\n"
        . "    </pdfaExtension:schemas>\n"
        . "   </rdf:Description>\n";

    /**
     * The XMP packet a conforming file carries in its catalog.
     *
     * /Info alone does not satisfy PDF/A: the identification has to be in XMP,
     * because that is the part of the file an archive can read without a PDF
     * parser. Everything here comes from what the caller already passed, so
     * nothing is invented; a document with no metadata gets a packet carrying
     * the identification and nothing else.
     *
     * **`$conformance` and `$ua` are claims the caller made**, and each is a
     * separate schema: `pdfaid` says which part and level of ISO 19005 the file
     * meets, and `pdfuaid` says it meets ISO 14289-1 as well. A reader that
     * checks one does not look at the other, so a file claiming both carries
     * both.
     *
     * @param array<string,string> $info the /Info entries, unescaped
     */
    public static function xmp(array $info, string $conformance = self::CONFORMANCE, bool $ua = false): string
    {
        $groups = ['dc' => '', 'pdf' => '', 'xmp' => ''];

        foreach ($info as $key => $value) {
            $mapping = self::XMP_KEYS[strtolower((string) $key)] ?? null;

            if ($mapping === null || trim($value) === '') {
                continue;
            }

            [$prefix, $name, $shape] = $mapping;

            $groups[$prefix] .= match ($shape) {
                'alt' => sprintf(
                    "    <%s:%s><rdf:Alt><rdf:li xml:lang=\"x-default\">%s</rdf:li></rdf:Alt></%s:%s>\n",
                    $prefix,
                    $name,
                    self::escape($value),
                    $prefix,
                    $name,
                ),

                'seq' => sprintf(
                    "    <%s:%s><rdf:Seq><rdf:li>%s</rdf:li></rdf:Seq></%s:%s>\n",
                    $prefix,
                    $name,
                    self::escape($value),
                    $prefix,
                    $name,
                ),

                'date' => sprintf(
                    "    <%s:%s>%s</%s:%s>\n",
                    $prefix,
                    $name,
                    self::escape(self::xmpDate($value)),
                    $prefix,
                    $name,
                ),

                default => sprintf(
                    "    <%s:%s>%s</%s:%s>\n",
                    $prefix,
                    $name,
                    self::escape($value),
                    $prefix,
                    $name,
                ),
            };
        }

        $body = sprintf(
            "   <rdf:Description rdf:about=\"\" xmlns:pdfaid=\"%s\">\n"
            . "    <pdfaid:part>%s</pdfaid:part>\n"
            . "    <pdfaid:conformance>%s</pdfaid:conformance>\n"
            . "   </rdf:Description>\n",
            self::NAMESPACES['pdfaid'],
            self::PART,
            $conformance,
        );

        if ($ua) {
            $body .= sprintf(
                    "   <rdf:Description rdf:about=\"\" xmlns:pdfuaid=\"%s\">\n"
                    . "    <pdfuaid:part>%s</pdfuaid:part>\n"
                    . "   </rdf:Description>\n",
                    self::NAMESPACES['pdfuaid'],
                    self::UA_PART,
                ) . self::UA_EXTENSION_SCHEMA;
        }

        foreach ($groups as $prefix => $properties) {
            if ($properties === '') {
                continue;
            }

            $body .= sprintf(
                "   <rdf:Description rdf:about=\"\" xmlns:%s=\"%s\">\n%s   </rdf:Description>\n",
                $prefix,
                self::NAMESPACES[$prefix],
                $properties,
            );
        }

        /*
         * The packet wrapper is the shape XMP fixes rather than a choice: the
         * id is the constant every packet carries, and the trailing `w` says
         * the packet may be written over in place.
         */

        return "<?xpacket begin=\"\u{FEFF}\" id=\"W5M0MpCehiHzreSzNTczkc9d\"?>\n"
            . "<x:xmpmeta xmlns:x=\"adobe:ns:meta/\">\n"
            . " <rdf:RDF xmlns:rdf=\"http://www.w3.org/1999/02/22-rdf-syntax-ns#\">\n"
            . $body
            . " </rdf:RDF>\n"
            . "</x:xmpmeta>\n"
            . "<?xpacket end=\"w\"?>\n";
    }

    /**
     * A /Info date as XMP spells one.
     *
     * The writer's own dates are `D:YYYYMMDDHHmmSS` with an optional offset,
     * and XMP wants ISO 8601. A value that is neither is passed through: the
     * caller wrote it and the two dictionaries have to agree, so guessing at
     * it would be the one way to make them disagree.
     */
    private static function xmpDate(string $value): string
    {
        if (!preg_match('/^D:(\d{4})(\d{2})(\d{2})(\d{2})?(\d{2})?(\d{2})?(.*)$/', $value, $m)) {
            return $value;
        }

        $stamp = sprintf(
            '%s-%s-%sT%s:%s:%s',
            $m[1],
            $m[2],
            $m[3],
            $m[4] ?: '00',
            $m[5] ?: '00',
            $m[6] ?: '00',
        );

        // `+02'00'` is how PDF writes an offset and `+02:00` is how XMP does.
        if (preg_match("/^([+\-])(\d{2})'?(\d{2})'?$/", trim($m[7]), $zone) === 1) {
            return $stamp . $zone[1] . $zone[2] . ':' . $zone[3];
        }

        return str_starts_with(trim($m[7]), 'Z') ? $stamp . 'Z' : $stamp;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
