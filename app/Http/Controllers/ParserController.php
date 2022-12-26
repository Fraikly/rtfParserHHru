<?php

namespace App\Http\Controllers;

use App\Http\Requests\ParserRequest;

class ParserController extends Controller
{
    public function store(ParserRequest $request)
    {
       if(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION)!="doc") {
           http_response_code(401);
           echo 'Wrong file extension. Try *.doc';
           exit;
       }

        $employment_histories_json = [];
        $resume_languages_json = [];
        $resume_educations_json = [];
        $resume = [];

        $file = $request->file('file');


        $document = $this->rtf2text($file);
        $linesArray = $this->getFormattedDoc($document);

        if(!str_contains($linesArray[0],"heading"))
        for ($i = 0; $i < count($linesArray); $i++) {
            if (str_contains($linesArray[$i], "Опыт работы")) {
                $workInfo = $this->getWorkInfo($linesArray, $i);
                $employment_histories_json = $workInfo[0];
                $i = $workInfo[1] - 1;
                unset($workInfo[1]);
            } else if (str_contains($linesArray[$i], "Образование")) {
                $educationInfo = $this->getEducationInfo($linesArray, $i);
                $resume_educations_json += $educationInfo[0];
                $i = $educationInfo[1] - 1;
                unset($educationInfo[1]);
            } else if (str_contains($linesArray[$i], "Знание языков")) {
                $resume_languages_json = $this->getLanguageInfo($linesArray, $i);
                break;
            }
        }

        $resume["resume"] = $resume_languages_json;
        $resume["resume"] += [
            'resume_employment_histories' => $employment_histories_json,
            'resume_educations' => $resume_educations_json
        ];

        echo json_encode($resume);


    }

    private function getLanguageInfo($linesArray, $numLine): array
    {
        $foreignLanguageLevels = [
            'A1'=> 'A1 - Начальный уровень',
            'A2'=> 'A2 - Базовый уровень',
            'B1'=> 'B1 - Средний уровень',
            'B2'=> 'B2 - Выше среднего',
            'C1'=> 'C1 - Продвинутый уровень',
            'C2'=> 'C2 - Владение в совершенстве',
        ];

        $languageInfo = array();
        $languageCount = -1;
        $lastLanguage = false;
        $linesArray[$numLine] = trim(explode('Знание языков', $linesArray[$numLine])[1]);
        for ($i = $numLine; $i < count($linesArray); $i++) {
            $languageLevel = '';
            if (str_contains($linesArray[$i], "Навыки")) {
                $linesArray[$i] = trim(explode('Навыки', $linesArray[$i])[0]);
                $lastLanguage = true;
            }
            $words = explode("—", $linesArray[$i]);
            $languageName = trim($words[0]);
            for ($j = 1; $j < count($words); $j++) {
                $languageLevel .= trim($words[$j]);
                foreach ($foreignLanguageLevels as $key => $foreignLanguageLevel){
                    if(str_contains($languageLevel,$key)){
                        $languageLevel = $foreignLanguageLevel;
                        break 2;
                    }
                }
                if ($j != count($words) - 1) {
                    $languageLevel .= " - ";
                }
            }
            if ($languageLevel == '' or $languageLevel == ' ') {
                $languageLevel = null;
            }
            if (str_contains($languageLevel, "Родной")) {
                $languageInfo += [
                    "native_language" => $languageName
                ];
            } else {
                $languageCount++;
                $languageInfo["foreign_languages"][$languageCount] = [
                    'language_name' => $languageName,
                    "language_level" => $languageLevel,
                ];
            }
            if ($lastLanguage)
                break;

        }
        if (!isset($languageInfo["native_language"]))
            $languageInfo["native_language"] = null;

        return $languageInfo;
    }

    private function getEducationType($lookFor, $separator, $string, $educationInfo, $educationCount): array
    {
        switch ($lookFor) {
            case 'faculty_name':
                $faculty_name = trim(explode($separator, $string)[0]);
                if (str_contains(mb_strtolower($faculty_name), "магистр")) {
                    $educationInfo[$educationCount - 1]["education_type"] = "Магистр";
                }
                $educationInfo[$educationCount - 1]["faculty_name"] = $faculty_name;
                break;
            case "institution_name":
                $educationInfo[$educationCount]["id"] = $educationCount;
                $institution_name = trim(explode($separator, $string)[1]);
                if (str_contains(mb_strtolower($institution_name), "институт") or
                    str_contains(mb_strtolower($institution_name), "университет")) {
                    $educationInfo[$educationCount]["education_type"] = "Высшее";

                } else if (str_contains(mb_strtolower($institution_name), "колледж") or
                    str_contains(mb_strtolower($institution_name), "техникум")) {
                    $educationInfo[$educationCount]["education_type"] = "Среднее профессиональное";

                } else {
                    $educationInfo[$educationCount]["education_type"] = "Среднее общее";
                }
                $educationInfo[$educationCount]["institution_name"] = $institution_name;
        }
        return $educationInfo;
    }

    private function getEducationInfo($linesArray, $numLine): array
    {
        $educationInfo = array();
        $educationCount = -1;
        $linesArray[$numLine] = explode('Образование. ', $linesArray[$numLine])[1];

        for ($i = $numLine; $i < count($linesArray); $i++) {
            if (str_contains($linesArray[$i], "Знание языков")) {
                if (explode("Ключевые навыки", $linesArray[$i])[0] != '') {
                    $educationCount++;
                    $educationInfo = $this->getEducationType('faculty_name', "Ключевые навыки", $linesArray[$i], $educationInfo, $educationCount);
                }
                return [$educationInfo, $i];
            }
            $words = explode(" ", $linesArray[$i]);
            foreach ($words as $word) {
                if (is_numeric($word)) {
                    $educationCount++;
                    if ($i != $numLine) {
                        $educationInfo = $this->getEducationType('faculty_name', $word, $linesArray[$i], $educationInfo, $educationCount);
                    }
                    $start_date = $this->getDate("01", "09", $word);
                    $finish_date = $this->getDate("01", "09", $word, "-4 years");
                    $educationInfo = $this->getEducationType('institution_name', $word, $linesArray[$i], $educationInfo, $educationCount);
                    $educationInfo[$educationCount] += [
                        "id" => $educationCount,
                        "education_type" => null,
                        "start_date" => $start_date,
                        "finish_date" => $finish_date,
                        "institution_name" => null,
                        "faculty_name" => null,
                        "file_name" => null,
                        "file_url" => null,
                        "skills" => null
                    ];
                }
            }
        }
        return [$educationInfo,count($linesArray)];
    }

    private function getWorkInfo($linesArray, $numLine): array
    {
        $workInfo = array();
        $linesArray[$numLine] = explode('Опыт работы', $linesArray[$numLine])[1];
        $workCount = -1;

        for ($i = $numLine; $i < count($linesArray); $i++) {
            if (str_contains($linesArray[$i], "Образование")) {
                return [$workInfo, $i];
            }
            $monthInfo = $this->findMonth($linesArray[$i]);
            if ($monthInfo != null) {
                $workCount++;
                $workInfo[$workCount] = [
                    'id' => $workCount,
                ];
                $workDates = explode($monthInfo["name"], $linesArray[$i])[1];

                $workInfo[$workCount] += $this->getEmploymentAndDismissalDate($monthInfo["name"] . $workDates, $monthInfo["num"]);

                $employerInfo = $this->getEmployerInfo($linesArray, $i);
                $i += $employerInfo["line"];
                unset($employerInfo["line"]);
                $workInfo[$workCount] += $employerInfo;
            }

        }

        return [$workInfo, $i];
    }


    private function getEmployerInfo($linesArray, $numLine): array
    {
        $employerInfo = array();
        $lineCount = 0;
        for ($i = $numLine + 2; $i < count($linesArray); $i++, $lineCount++) {
            if (mb_strpos($linesArray[$i], "Образование")) {
                $employerInfo[] = explode("Образование", $linesArray[$i])[0];
                break;
            } else {
                $monthInfo = $this->findMonth($linesArray[$i]);
                if ($monthInfo === null) {
                    $employerInfo[] = $linesArray[$i];
                } else {
                    $employerInfo[] = explode($monthInfo["name"], $linesArray[$i])[0];
                    break;
                }
            }
        }
        $detailedInfo = explode(',', $employerInfo[1]);
        if ($detailedInfo == " " or mb_strlen($detailedInfo[0]) === 0) {
            $employer_city = null;
            $employer_site = null;
        } else {
            $employer_city = $detailedInfo[0];
            if (count($detailedInfo) === 1) {
                $employer_site = null;
            } else {
                $employer_site = trim($detailedInfo[1]);
            }

        }
        return [
            "employer_name" => $employerInfo[0],
            "employer_city" => $employer_city,
            'employer_site' => $employer_site,
            "position" => $employerInfo[2],
            "work_experience" => trim($employerInfo[3]),
            "skills" => null,
            'line' => $lineCount
        ];

    }


    private  function getEmploymentAndDismissalDate($str, $monthNum): array
    {
        $dates = explode('—', $str);
        $employment_date = null;
        $dismissal_date = null;
        foreach ($dates as $numDate => $date) {
            if ($numDate === 0) {
                $year = explode(' ', $date)[1];
                $monthNum++;
                $employment_date = $this->getDate("01", $monthNum, $year);
            } else {
                if(str_contains($date,"настоящее время")){
                    break;
                }

                $monthInfo = $this->findMonth($date);
                if ($monthInfo != null) {
                    $year='';
                    $wordNum=2;
                    while (!is_numeric($year)){
                        $year = explode(' ', $date)[$wordNum];
                        $wordNum++;
                    }

                    $monthNum = $monthInfo["num"] + 1;
                    $dismissal_date = $this->getDate("01", $monthNum, $year);
                }
            }
        }
        return [
            "employment_date" => $employment_date,
            "dismissal_date" => $dismissal_date
        ];

    }

    private function getDate($d, $m, $y, $detailed = ''): string
    {
        return date("d.m.Y", strtotime("{$d}.{$m}.{$y} {$detailed}"));
    }

    private function findMonth($str): ?array
    {
        $monthsArray = ['Январь', "Февраль", 'Март', "Апрель", "Май", "Июнь", "Июль", "Август", "Сентябрь", "Октябрь", "Ноябрь", "Декабрь"];
        $words = explode(" ", $str);
        foreach ($words as $word)
            foreach ($monthsArray as $numMonth => $month) {
                if ($word == $month)
                    return [
                        'num' => $numMonth,
                        'name' => $month
                    ];
            }
        return null;
    }


    private function rtf_isPlainText($s): bool
    {
        $arrfailAt = array("*", "fonttbl", "colortbl", "datastore", "themedata");
        for ($i = 0; $i < count($arrfailAt); $i++)
            if (!empty($s[$arrfailAt[$i]])) return false;
        return true;
    }

    private function rtf2text($filename): string
    {
        // Read the data from the input file.
        $text = file_get_contents($filename);
        if (!strlen($text))
            return "";

        // Create empty stack array.
        $document = "";
        $stack = array();
        $j = -1;
        // Read the data character-by- character…
        for ($i = 0, $len = strlen($text); $i < $len; $i++) {
            $c = $text[$i];

            switch ($c) {
                // the most important key word backslash
                case "\\":
                    // read next character
                    $nc = $text[$i + 1];

                    // If it is another backslash or nonbreaking space or hyphen,
                    // then the character is plain text and add it to the output stream.

                    if ($nc == '\\' && $this->rtf_isPlainText($stack[$j])) $document .= '\\';
                    elseif ($nc == '~' && $this->rtf_isPlainText($stack[$j])) $document .= ' ';
                    elseif ($nc == '_' && $this->rtf_isPlainText($stack[$j])) $document .= '-';
                    // If it is an asterisk mark, add it to the stack.
                    elseif ($nc == '*') $stack[$j]["*"] = true;
                    // If it is a single quote, read next two characters that are the hexadecimal notation
                    // of a character we should add to the output stream.
                    elseif ($nc == "'") {
                        $hex = substr($text, $i + 2, 2);
                        if ($this->rtf_isPlainText($stack[$j]))
                            $document .= trim(html_entity_decode("&#" . hexdec($hex) . ";"));
                        //Shift the pointer.
                        $i += 2;
                        // Since, we’ve found the alphabetic character, the next characters are control word
                        // and, possibly, some digit parameter.
                    } elseif ($nc >= 'a' && $nc <= 'z' || $nc >= 'A' && $nc <= 'Z') {
                        $word = "";
                        $param = null;

                        // Start reading characters after the backslash.
                        for ($k = $i + 1, $m = 0; $k < strlen($text); $k++, $m++) {
                            $nc = $text[$k];
                            // If the current character is a letter and there were no digits before it,
                            // then we’re still reading the control word. If there were digits, we should stop
                            // since we reach the end of the control word.
                            if ($nc >= 'a' && $nc <= 'z' || $nc >= 'A' && $nc <= 'Z') {
                                if (empty($param))
                                    $word .= $nc;
                                else
                                    break;
                                // If it is a digit, store the parameter.
                            } elseif ($nc >= '0' && $nc <= '9')
                                $param .= $nc;
                            // Since minus sign may occur only before a digit parameter, check whether
                            // $param is empty. Otherwise, we reach the end of the control word.
                            elseif ($nc == '-') {
                                if (empty($param))
                                    $param .= $nc;
                                else
                                    break;
                            } else
                                break;
                        }
                        // Shift the pointer on the number of read characters.
                        $i += $m - 1;

                        // Start analyzing what we’ve read. We are interested mostly in control words.
                        $toText = "";
                        switch (strtolower($word)) {
                            // If the control word is "u", then its parameter is the decimal notation of the
                            // Unicode character that should be added to the output stream.
                            // We need to check whether the stack contains \ucN control word. If it does,
                            // we should remove the N characters from the output stream.
                            case "u":
                                $toText .= html_entity_decode("&#x" . dechex($param) . ";");
                                $ucDelta = @$stack[$j]["uc"];
                                if ($ucDelta > 0)
                                    $i += $ucDelta;
                                break;
                            // Select line feeds, spaces and tabs.
                            case "par":
                            case "page":
                            case "column":
                            case "line":
                            case "lbr":
                                $toText .= "\n";
                                break;
                            case "emspace":
                            case "enspace":
                            case "qmspace":
                                $toText .= " ";
                                break;
                            case "tab":
                                $toText .= "\t";
                                break;
                            // Add current date and time instead of corresponding labels.
                            case "chdate":
                                $toText .= date("m.d.Y");
                                break;
                            case "chdpl":
                                $toText .= date("l, j F Y");
                                break;
                            case "chdpa":
                                $toText .= date("D, j M Y");
                                break;
                            case "chtime":
                                $toText .= date("H:i:s");
                                break;
                            // Replace some reserved characters to their html analogs.
                            case "emdash":
                                $toText .= html_entity_decode("&mdash;");
                                break;
                            case "endash":
                                $toText .= html_entity_decode("&ndash;");
                                break;
                            case "bullet":
                                $toText .= html_entity_decode("&#149;");
                                break;
                            case "lquote":
                                $toText .= html_entity_decode("&lsquo;");
                                break;
                            case "rquote":
                                $toText .= html_entity_decode("&rsquo;");
                                break;
                            case "ldblquote":
                                $toText .= html_entity_decode("&laquo;");
                                break;
                            case "rdblquote":
                                $toText .= html_entity_decode("&raquo;");
                                break;
                            // Add all other to the control words stack. If a control word
                            // does not include parameters, set &param to true.
                            default:
                                $stack[$j][strtolower($word)] = empty($param) ? true : $param;
                                break;
                        }
                        // Add data to the output stream if required.
                        if (isset($stack[$j]))
                            if ($this->rtf_isPlainText($stack[$j]))

                                $document .= $toText;
                    }

                    $i++;
                    break;
                // If we read the opening brace {, then new subgroup starts and we add
                // new array stack element and write the data from previous stack element to it.
                case "{":
                    $j++;
                    if (isset($stack[$j]))
                        array_push($stack, $stack[$j]);
                    break;
                // If we read the closing brace }, then we reach the end of subgroup and should remove
                // the last stack element.
                case "}":
                    array_pop($stack);
                    $j--;
                    break;
                // Skip “trash”.
                case '\0':
                case '\r':
                case '\f':
                case '\n':
                    break;
                // Add other data to the output stream if required.
                default:
                    if (isset($stack[$j]))
                        if ($this->rtf_isPlainText($stack[$j])) {
                            $document .= $c;
                        }

                    break;
            }
        }
        // Return result.
        return $document;
    }

    private function getFormattedDoc($doc): array
    {

        $formattedLinesArray = [];
        $separator = "~~~";
        $encoding=false;

        $linesArray = explode("\n", $doc);

        unset($linesArray[0], $linesArray[1], $linesArray[3]);
        $linesArray = array_values($linesArray);
        for($i=0;$i<count($linesArray);$i++){
            $line = $linesArray[$i];
            if ($line != " " or $line != ""){
                $line = preg_replace('`(\b[А-ЯЁA-Z]{2,})`u', "{$separator}$0{$separator}", $line);
                $line = trim(preg_replace('#\w\K[A-ZЁА-Я]#u', ". $0", $line));

                if (str_contains($line, $separator)) {
                    $abriviatArray = explode($separator, $line);
                    $numLastElem = count($abriviatArray) - 1;
                    for ($j = 1; $j < $numLastElem; $j++) {
                        $abriviatArray[$j] = str_replace('. ', "", $abriviatArray[$j]);
                    }
                    if ($abriviatArray[$numLastElem] != '') {
                        $firstLetterOfTheLastElem = mb_substr($abriviatArray[$numLastElem], 0, 1);

                        if (!ctype_punct($firstLetterOfTheLastElem) and $firstLetterOfTheLastElem != ' ' and $firstLetterOfTheLastElem === strtolower($firstLetterOfTheLastElem)) {
                            $lost_letter = mb_substr($abriviatArray[$numLastElem - 1], -1);
                            $abriviatArray[$numLastElem - 1] = mb_substr($abriviatArray[$numLastElem - 1], 0, mb_strlen($abriviatArray[$numLastElem - 1]) - 1) . " ";
                            $abriviatArray[$numLastElem] = $lost_letter . $abriviatArray[$numLastElem];
                        }
                    }

                    $line = implode("", $abriviatArray);
                }

                $formattedLinesArray[] = $line;
            }


        }
        if($encoding){
            unset($formattedLinesArray[count($formattedLinesArray)-1]);
        }

        return $formattedLinesArray;
    }

private function decodeString($str): bool|string
{
    $str = iconv('utf-8', 'cp1252//IGNORE', $str);
    return iconv("windows-1251","utf-8",$str);
}

}
