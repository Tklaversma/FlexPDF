<?php
declare(strict_types=1);

namespace FlexPDF\Engine;

/**
 * Adobe Core-14 metrics for Helvetica / Helvetica-Bold.
 * Widths are in 1/1000 em, exactly as the AFM files define them.
 * This exists so the layout engine can measure text without a browser.
 */
final class Font
{
    private const array HELVETICA = [
        32=>278,33=>278,34=>355,35=>556,36=>556,37=>889,38=>667,39=>191,40=>333,41=>333,
        42=>389,43=>584,44=>278,45=>333,46=>278,47=>278,48=>556,49=>556,50=>556,51=>556,
        52=>556,53=>556,54=>556,55=>556,56=>556,57=>556,58=>278,59=>278,60=>584,61=>584,
        62=>584,63=>556,64=>1015,65=>667,66=>667,67=>722,68=>722,69=>667,70=>611,71=>778,
        72=>722,73=>278,74=>500,75=>667,76=>556,77=>833,78=>722,79=>778,80=>667,81=>778,
        82=>722,83=>667,84=>611,85=>722,86=>667,87=>944,88=>667,89=>667,90=>611,91=>278,
        92=>278,93=>278,94=>469,95=>556,96=>333,97=>556,98=>556,99=>500,100=>556,101=>556,
        102=>278,103=>556,104=>556,105=>222,106=>222,107=>500,108=>222,109=>833,110=>556,
        111=>556,112=>556,113=>556,114=>333,115=>500,116=>278,117=>556,118=>500,119=>722,
        120=>500,121=>500,122=>500,123=>334,124=>260,125=>334,126=>584,
        128=>556,130=>222,131=>556,132=>333,133=>1000,134=>556,135=>556,136=>333,
        137=>1000,138=>667,139=>333,140=>1000,142=>611,145=>222,146=>222,147=>333,
        148=>333,149=>350,150=>556,151=>1000,152=>333,153=>1000,154=>500,155=>333,
        156=>944,158=>500,159=>667,
        160=>278,161=>333,162=>556,163=>556,164=>556,165=>556,166=>260,167=>556,
        168=>333,169=>737,170=>370,171=>556,172=>584,173=>333,174=>737,175=>333,
        176=>400,177=>584,178=>333,179=>333,180=>333,181=>556,182=>537,183=>278,
        184=>333,185=>333,186=>365,187=>556,188=>834,189=>834,190=>834,191=>611,
        192=>667,193=>667,194=>667,195=>667,196=>667,197=>667,198=>1000,199=>722,
        200=>667,201=>667,202=>667,203=>667,204=>278,205=>278,206=>278,207=>278,
        208=>722,209=>722,210=>778,211=>778,212=>778,213=>778,214=>778,215=>584,
        216=>778,217=>722,218=>722,219=>722,220=>722,221=>667,222=>667,223=>611,
        224=>556,225=>556,226=>556,227=>556,228=>556,229=>556,230=>889,231=>500,
        232=>556,233=>556,234=>556,235=>556,236=>278,237=>278,238=>278,239=>278,
        240=>556,241=>556,242=>556,243=>556,244=>556,245=>556,246=>556,247=>584,
        248=>611,249=>556,250=>556,251=>556,252=>556,253=>500,254=>556,255=>500,
    ];

    private const array HELVETICA_BOLD = [
        32=>278,33=>333,34=>474,35=>556,36=>556,37=>889,38=>722,39=>238,40=>333,41=>333,
        42=>389,43=>584,44=>278,45=>333,46=>278,47=>278,48=>556,49=>556,50=>556,51=>556,
        52=>556,53=>556,54=>556,55=>556,56=>556,57=>556,58=>333,59=>333,60=>584,61=>584,
        62=>584,63=>611,64=>975,65=>722,66=>722,67=>722,68=>722,69=>667,70=>611,71=>778,
        72=>722,73=>278,74=>556,75=>722,76=>611,77=>833,78=>722,79=>778,80=>667,81=>778,
        82=>722,83=>667,84=>611,85=>722,86=>667,87=>944,88=>667,89=>667,90=>611,91=>333,
        92=>278,93=>333,94=>584,95=>556,96=>333,97=>556,98=>611,99=>556,100=>611,101=>556,
        102=>333,103=>611,104=>611,105=>278,106=>278,107=>556,108=>278,109=>889,110=>611,
        111=>611,112=>611,113=>611,114=>389,115=>556,116=>333,117=>611,118=>556,119=>778,
        120=>556,121=>556,122=>500,123=>389,124=>280,125=>389,126=>584,
        128=>556,130=>278,131=>556,132=>500,133=>1000,134=>556,135=>556,136=>333,
        137=>1000,138=>667,139=>333,140=>1000,142=>611,145=>278,146=>278,147=>500,
        148=>500,149=>350,150=>556,151=>1000,152=>333,153=>1000,154=>556,155=>333,
        156=>944,158=>500,159=>667,
        160=>278,161=>333,162=>556,163=>556,164=>556,165=>556,166=>280,167=>556,
        168=>333,169=>737,170=>370,171=>556,172=>584,173=>333,174=>737,175=>333,
        176=>400,177=>584,178=>333,179=>333,180=>333,181=>611,182=>556,183=>278,
        184=>333,185=>333,186=>365,187=>556,188=>834,189=>834,190=>834,191=>611,
        192=>722,193=>722,194=>722,195=>722,196=>722,197=>722,198=>1000,199=>722,
        200=>667,201=>667,202=>667,203=>667,204=>278,205=>278,206=>278,207=>278,
        208=>722,209=>722,210=>778,211=>778,212=>778,213=>778,214=>778,215=>584,
        216=>778,217=>722,218=>722,219=>722,220=>722,221=>667,222=>667,223=>611,
        224=>556,225=>556,226=>556,227=>556,228=>556,229=>556,230=>889,231=>556,
        232=>556,233=>556,234=>556,235=>556,236=>278,237=>278,238=>278,239=>278,
        240=>611,241=>611,242=>611,243=>611,244=>611,245=>611,246=>611,247=>584,
        248=>611,249=>611,250=>611,251=>611,252=>611,253=>556,254=>611,255=>556,
    ];

    private const array TIMES = [
        32=>250,33=>333,34=>408,35=>500,36=>500,37=>833,38=>778,39=>180,40=>333,41=>333,
        42=>500,43=>564,44=>250,45=>333,46=>250,47=>278,48=>500,49=>500,50=>500,51=>500,
        52=>500,53=>500,54=>500,55=>500,56=>500,57=>500,58=>278,59=>278,60=>564,61=>564,
        62=>564,63=>444,64=>921,65=>722,66=>667,67=>667,68=>722,69=>611,70=>556,71=>722,
        72=>722,73=>333,74=>389,75=>722,76=>611,77=>889,78=>722,79=>722,80=>556,81=>722,
        82=>667,83=>556,84=>611,85=>722,86=>722,87=>944,88=>722,89=>722,90=>611,91=>333,
        92=>278,93=>333,94=>469,95=>500,96=>333,97=>444,98=>500,99=>444,100=>500,101=>444,
        102=>333,103=>500,104=>500,105=>278,106=>278,107=>500,108=>278,109=>778,110=>500,111=>500,
        112=>500,113=>500,114=>333,115=>389,116=>278,117=>500,118=>500,119=>722,120=>500,121=>500,
        122=>444,123=>480,124=>200,125=>480,126=>541,128=>500,130=>333,131=>500,132=>444,133=>1000,
        134=>500,135=>500,136=>333,137=>1000,138=>556,139=>333,140=>889,142=>611,145=>333,146=>333,
        147=>444,148=>444,149=>350,150=>500,151=>1000,152=>333,153=>980,154=>389,155=>333,156=>722,
        158=>444,159=>722,160=>250,161=>333,162=>500,163=>500,164=>500,165=>500,166=>200,167=>500,
        168=>333,169=>760,170=>276,171=>500,172=>564,173=>333,174=>760,175=>333,176=>400,177=>564,
        178=>300,179=>300,180=>333,181=>500,182=>453,183=>250,184=>333,185=>300,186=>310,187=>500,
        188=>750,189=>750,190=>750,191=>444,192=>722,193=>722,194=>722,195=>722,196=>722,197=>722,
        198=>889,199=>667,200=>611,201=>611,202=>611,203=>611,204=>333,205=>333,206=>333,207=>333,
        208=>722,209=>722,210=>722,211=>722,212=>722,213=>722,214=>722,215=>564,216=>722,217=>722,
        218=>722,219=>722,220=>722,221=>722,222=>556,223=>500,224=>444,225=>444,226=>444,227=>444,
        228=>444,229=>444,230=>667,231=>444,232=>444,233=>444,234=>444,235=>444,236=>278,237=>278,
        238=>278,239=>278,240=>500,241=>500,242=>500,243=>500,244=>500,245=>500,246=>500,247=>564,
        248=>500,249=>500,250=>500,251=>500,252=>500,253=>500,254=>500,255=>500,
    ];

    private const array TIMES_BOLD = [
        32=>250,33=>333,34=>555,35=>500,36=>500,37=>1000,38=>833,39=>278,40=>333,41=>333,
        42=>500,43=>570,44=>250,45=>333,46=>250,47=>278,48=>500,49=>500,50=>500,51=>500,
        52=>500,53=>500,54=>500,55=>500,56=>500,57=>500,58=>333,59=>333,60=>570,61=>570,
        62=>570,63=>500,64=>930,65=>722,66=>667,67=>722,68=>722,69=>667,70=>611,71=>778,
        72=>778,73=>389,74=>500,75=>778,76=>667,77=>944,78=>722,79=>778,80=>611,81=>778,
        82=>722,83=>556,84=>667,85=>722,86=>722,87=>1000,88=>722,89=>722,90=>667,91=>333,
        92=>278,93=>333,94=>581,95=>500,96=>333,97=>500,98=>556,99=>444,100=>556,101=>444,
        102=>333,103=>500,104=>556,105=>278,106=>333,107=>556,108=>278,109=>833,110=>556,111=>500,
        112=>556,113=>556,114=>444,115=>389,116=>333,117=>556,118=>500,119=>722,120=>500,121=>500,
        122=>444,123=>394,124=>220,125=>394,126=>520,128=>500,130=>333,131=>500,132=>500,133=>1000,
        134=>500,135=>500,136=>333,137=>1000,138=>556,139=>333,140=>1000,142=>667,145=>333,146=>333,
        147=>500,148=>500,149=>350,150=>500,151=>1000,152=>333,153=>1000,154=>389,155=>333,156=>722,
        158=>444,159=>722,160=>250,161=>333,162=>500,163=>500,164=>500,165=>500,166=>220,167=>500,
        168=>333,169=>747,170=>300,171=>500,172=>570,173=>333,174=>747,175=>333,176=>400,177=>570,
        178=>300,179=>300,180=>333,181=>556,182=>540,183=>250,184=>333,185=>300,186=>330,187=>500,
        188=>750,189=>750,190=>750,191=>500,192=>722,193=>722,194=>722,195=>722,196=>722,197=>722,
        198=>1000,199=>722,200=>667,201=>667,202=>667,203=>667,204=>389,205=>389,206=>389,207=>389,
        208=>722,209=>722,210=>778,211=>778,212=>778,213=>778,214=>778,215=>570,216=>778,217=>722,
        218=>722,219=>722,220=>722,221=>722,222=>611,223=>556,224=>500,225=>500,226=>500,227=>500,
        228=>500,229=>500,230=>722,231=>444,232=>444,233=>444,234=>444,235=>444,236=>278,237=>278,
        238=>278,239=>278,240=>500,241=>556,242=>500,243=>500,244=>500,245=>500,246=>500,247=>570,
        248=>500,249=>556,250=>556,251=>556,252=>556,253=>500,254=>556,255=>500,
    ];

    private const array TIMES_ITALIC = [
        32=>250,33=>333,34=>420,35=>500,36=>500,37=>833,38=>778,39=>214,40=>333,41=>333,
        42=>500,43=>675,44=>250,45=>333,46=>250,47=>278,48=>500,49=>500,50=>500,51=>500,
        52=>500,53=>500,54=>500,55=>500,56=>500,57=>500,58=>333,59=>333,60=>675,61=>675,
        62=>675,63=>500,64=>920,65=>611,66=>611,67=>667,68=>722,69=>611,70=>611,71=>722,
        72=>722,73=>333,74=>444,75=>667,76=>556,77=>833,78=>667,79=>722,80=>611,81=>722,
        82=>611,83=>500,84=>556,85=>722,86=>611,87=>833,88=>611,89=>556,90=>556,91=>389,
        92=>278,93=>389,94=>422,95=>500,96=>333,97=>500,98=>500,99=>444,100=>500,101=>444,
        102=>278,103=>500,104=>500,105=>278,106=>278,107=>444,108=>278,109=>722,110=>500,111=>500,
        112=>500,113=>500,114=>389,115=>389,116=>278,117=>500,118=>444,119=>667,120=>444,121=>444,
        122=>389,123=>400,124=>275,125=>400,126=>541,128=>500,130=>333,131=>500,132=>556,133=>889,
        134=>500,135=>500,136=>333,137=>1000,138=>500,139=>333,140=>944,142=>556,145=>333,146=>333,
        147=>556,148=>556,149=>350,150=>500,151=>889,152=>333,153=>980,154=>389,155=>333,156=>667,
        158=>389,159=>556,160=>250,161=>389,162=>500,163=>500,164=>500,165=>500,166=>275,167=>500,
        168=>333,169=>760,170=>276,171=>500,172=>675,173=>333,174=>760,175=>333,176=>400,177=>675,
        178=>300,179=>300,180=>333,181=>500,182=>523,183=>250,184=>333,185=>300,186=>310,187=>500,
        188=>750,189=>750,190=>750,191=>500,192=>611,193=>611,194=>611,195=>611,196=>611,197=>611,
        198=>889,199=>667,200=>611,201=>611,202=>611,203=>611,204=>333,205=>333,206=>333,207=>333,
        208=>722,209=>667,210=>722,211=>722,212=>722,213=>722,214=>722,215=>675,216=>722,217=>722,
        218=>722,219=>722,220=>722,221=>556,222=>611,223=>500,224=>500,225=>500,226=>500,227=>500,
        228=>500,229=>500,230=>667,231=>444,232=>444,233=>444,234=>444,235=>444,236=>278,237=>278,
        238=>278,239=>278,240=>500,241=>500,242=>500,243=>500,244=>500,245=>500,246=>500,247=>675,
        248=>500,249=>500,250=>500,251=>500,252=>500,253=>444,254=>500,255=>444,
    ];

    private const array TIMES_BOLD_ITALIC = [
        32=>250,33=>389,34=>555,35=>500,36=>500,37=>833,38=>778,39=>278,40=>333,41=>333,
        42=>500,43=>570,44=>250,45=>333,46=>250,47=>278,48=>500,49=>500,50=>500,51=>500,
        52=>500,53=>500,54=>500,55=>500,56=>500,57=>500,58=>333,59=>333,60=>570,61=>570,
        62=>570,63=>500,64=>832,65=>667,66=>667,67=>667,68=>722,69=>667,70=>667,71=>722,
        72=>778,73=>389,74=>500,75=>667,76=>611,77=>889,78=>722,79=>722,80=>611,81=>722,
        82=>667,83=>556,84=>611,85=>722,86=>667,87=>889,88=>667,89=>611,90=>611,91=>333,
        92=>278,93=>333,94=>570,95=>500,96=>333,97=>500,98=>500,99=>444,100=>500,101=>444,
        102=>333,103=>500,104=>556,105=>278,106=>278,107=>500,108=>278,109=>778,110=>556,111=>500,
        112=>500,113=>500,114=>389,115=>389,116=>278,117=>556,118=>444,119=>667,120=>500,121=>444,
        122=>389,123=>348,124=>220,125=>348,126=>570,128=>500,130=>333,131=>500,132=>500,133=>1000,
        134=>500,135=>500,136=>333,137=>1000,138=>556,139=>333,140=>944,142=>611,145=>333,146=>333,
        147=>500,148=>500,149=>350,150=>500,151=>1000,152=>333,153=>1000,154=>389,155=>333,156=>722,
        158=>389,159=>611,160=>250,161=>389,162=>500,163=>500,164=>500,165=>500,166=>220,167=>500,
        168=>333,169=>747,170=>266,171=>500,172=>606,173=>333,174=>747,175=>333,176=>400,177=>570,
        178=>300,179=>300,180=>333,181=>576,182=>500,183=>250,184=>333,185=>300,186=>300,187=>500,
        188=>750,189=>750,190=>750,191=>500,192=>667,193=>667,194=>667,195=>667,196=>667,197=>667,
        198=>944,199=>667,200=>667,201=>667,202=>667,203=>667,204=>389,205=>389,206=>389,207=>389,
        208=>722,209=>722,210=>722,211=>722,212=>722,213=>722,214=>722,215=>570,216=>722,217=>722,
        218=>722,219=>722,220=>722,221=>611,222=>611,223=>500,224=>500,225=>500,226=>500,227=>500,
        228=>500,229=>500,230=>722,231=>444,232=>444,233=>444,234=>444,235=>444,236=>278,237=>278,
        238=>278,239=>278,240=>500,241=>556,242=>500,243=>500,244=>500,245=>500,246=>500,247=>570,
        248=>500,249=>556,250=>556,251=>556,252=>556,253=>444,254=>500,255=>444,
    ];

    private const array COURIER = [
        32=>600,33=>600,34=>600,35=>600,36=>600,37=>600,38=>600,39=>600,40=>600,41=>600,
        42=>600,43=>600,44=>600,45=>600,46=>600,47=>600,48=>600,49=>600,50=>600,51=>600,
        52=>600,53=>600,54=>600,55=>600,56=>600,57=>600,58=>600,59=>600,60=>600,61=>600,
        62=>600,63=>600,64=>600,65=>600,66=>600,67=>600,68=>600,69=>600,70=>600,71=>600,
        72=>600,73=>600,74=>600,75=>600,76=>600,77=>600,78=>600,79=>600,80=>600,81=>600,
        82=>600,83=>600,84=>600,85=>600,86=>600,87=>600,88=>600,89=>600,90=>600,91=>600,
        92=>600,93=>600,94=>600,95=>600,96=>600,97=>600,98=>600,99=>600,100=>600,101=>600,
        102=>600,103=>600,104=>600,105=>600,106=>600,107=>600,108=>600,109=>600,110=>600,111=>600,
        112=>600,113=>600,114=>600,115=>600,116=>600,117=>600,118=>600,119=>600,120=>600,121=>600,
        122=>600,123=>600,124=>600,125=>600,126=>600,128=>600,130=>600,131=>600,132=>600,133=>600,
        134=>600,135=>600,136=>600,137=>600,138=>600,139=>600,140=>600,142=>600,145=>600,146=>600,
        147=>600,148=>600,149=>600,150=>600,151=>600,152=>600,153=>600,154=>600,155=>600,156=>600,
        158=>600,159=>600,160=>600,161=>600,162=>600,163=>600,164=>600,165=>600,166=>600,167=>600,
        168=>600,169=>600,170=>600,171=>600,172=>600,173=>600,174=>600,175=>600,176=>600,177=>600,
        178=>600,179=>600,180=>600,181=>600,182=>600,183=>600,184=>600,185=>600,186=>600,187=>600,
        188=>600,189=>600,190=>600,191=>600,192=>600,193=>600,194=>600,195=>600,196=>600,197=>600,
        198=>600,199=>600,200=>600,201=>600,202=>600,203=>600,204=>600,205=>600,206=>600,207=>600,
        208=>600,209=>600,210=>600,211=>600,212=>600,213=>600,214=>600,215=>600,216=>600,217=>600,
        218=>600,219=>600,220=>600,221=>600,222=>600,223=>600,224=>600,225=>600,226=>600,227=>600,
        228=>600,229=>600,230=>600,231=>600,232=>600,233=>600,234=>600,235=>600,236=>600,237=>600,
        238=>600,239=>600,240=>600,241=>600,242=>600,243=>600,244=>600,245=>600,246=>600,247=>600,
        248=>600,249=>600,250=>600,251=>600,252=>600,253=>600,254=>600,255=>600,
    ];

    /**
     * The base-14 faces this engine measures, by their PDF BaseFont name.
     *
     * Widths come from the Adobe AFMs, re-keyed from glyph name to WinAnsi
     * code; the mapping was checked by regenerating the Helvetica table above
     * from Helvetica.afm and requiring all 218 entries to come back
     * unchanged. Courier takes `ascent` and `descent` from the AFM's own
     * Ascender and Descender.
     *
     * **`ascent`, `descent` and `gap` no longer decide where a line box goes**,
     * and round 71 is where that stopped. They were a baseline correction
     * fitted to Chrome across 29 sizes, which is why Helvetica reads 808 where
     * its own AFM says 718: `ascent` plus half the gap comes to 0.920 em, and
     * Chrome's mean is 0.9207. A fit to a mean is right at almost no single
     * size, though, and Chrome's own arithmetic is exact:
     * `round(realAscent * px) + round(0.15 * px)` above the baseline and
     * `round(realDescent * px)` below it, each term rounded on its own.
     * {@see fontBox} has answered that since round 66 and {@see lineBand} and
     * {@see lineSpacing} read it now, so the fitted triple is left here for the
     * zero-size fallback in `normalLineHeight()` and for nothing else.
     * `docs/harness/probes/PY-family-baseline.html` is the measurement and it
     * is **29 of 29 sizes exact on all three families**, baseline and line
     * height alike, where the fitted pair was 0 of 29 on every baseline and
     * put Courier's out by as much as 6.4 CSS pixels.
     *
     * **Which face `Courier New` and `Times New Roman` get was answered in
     * round 85**, and the answer is their own. Both carry four entries of their
     * own below with the `hhea` and `OS/2` fields of the installed macOS faces,
     * so their baselines are 0.8338 em and 0.9017 the way Chrome's are. The
     * generic `monospace` still resolves to Adobe's Courier and was left alone,
     * so `<code>` and `<pre>` did not move. Defects DM and HV.
     *
     * `gap` has no AFM field and now reaches only that fallback. It was set so
     * `line-height: normal` landed where the face a viewer substitutes puts it:
     * Helvetica's 224 was measured against Chrome across eleven sizes, and the
     * other two come from the hhea tables of Times New Roman (1.1499) and
     * Courier New (1.1328).
     *
     * The oblique faces reuse their upright widths, which is what obliquing
     * means: the same advances, slanted.
     *
     * **`marker` is the face's REAL ascent and is used for nothing else.** A
     * list marker's shape is a fraction of the ascent a browser holds for the
     * face, and the `ascent` beside it is a baseline correction rather than a
     * metric, so a bullet built on it comes out a pixel small at every size.
     * These three are the faces macOS actually has, read off the descriptors
     * Chrome embeds: 770 for Helvetica, 750 for Times and 754 for Courier.
     * One value covers a family's four faces, checked on the bold pair.
     *
     * @var array<string,array{widths:string,ascent:int,descent:int,gap:int,xheight:int,marker:int}>
     */
    /*
     * `ascent`, `descent` and `gap` are a baseline correction fitted to where
     * Chrome puts the LINE box, which is why Helvetica reads 808 where its own
     * AFM says 718. `real` is a different pair and a measured one: it is what
     * Chrome writes into the FontDescriptor of the file it embeds, and it is
     * what the FONT box is built from. Each `real` pair sums to 1000 exactly,
     * because CoreText normalises them, and each is a whole number of 2048ths.
     * Defect GC.
     */
    private const array FACES = [
        'Helvetica'             => ['widths' => 'HELVETICA', 'ascent' => 808, 'descent' => 117, 'gap' => 224, 'xheight' => 523, 'capheight' => 718, 'marker' => 770, 'realAscent' => 770.01953125, 'realDescent' => 229.98046875],
        'Helvetica-Bold'        => ['widths' => 'HELVETICA_BOLD', 'ascent' => 808, 'descent' => 117, 'gap' => 224, 'xheight' => 532, 'capheight' => 718, 'marker' => 770, 'realAscent' => 770.01953125, 'realDescent' => 229.98046875],
        'Helvetica-Oblique'     => ['widths' => 'HELVETICA', 'ascent' => 808, 'descent' => 117, 'gap' => 224, 'xheight' => 523, 'capheight' => 718, 'marker' => 770, 'realAscent' => 770.01953125, 'realDescent' => 229.98046875],
        'Helvetica-BoldOblique' => ['widths' => 'HELVETICA_BOLD', 'ascent' => 808, 'descent' => 117, 'gap' => 224, 'xheight' => 532, 'capheight' => 718, 'marker' => 770, 'realAscent' => 770.01953125, 'realDescent' => 229.98046875],
        'Times-Roman'           => ['widths' => 'TIMES', 'ascent' => 777, 'descent' => 123, 'gap' => 250, 'xheight' => 450, 'capheight' => 662, 'marker' => 750, 'realAscent' => 750.0, 'realDescent' => 250.0],
        'Times-Bold'            => ['widths' => 'TIMES_BOLD', 'ascent' => 777, 'descent' => 123, 'gap' => 250, 'xheight' => 461, 'capheight' => 676, 'marker' => 750, 'realAscent' => 750.0, 'realDescent' => 250.0],
        'Times-Italic'          => ['widths' => 'TIMES_ITALIC', 'ascent' => 777, 'descent' => 123, 'gap' => 250, 'xheight' => 441, 'capheight' => 653, 'marker' => 750, 'realAscent' => 750.0, 'realDescent' => 250.0],
        'Times-BoldItalic'      => ['widths' => 'TIMES_BOLD_ITALIC', 'ascent' => 777, 'descent' => 123, 'gap' => 250, 'xheight' => 462, 'capheight' => 669, 'marker' => 750, 'realAscent' => 750.0, 'realDescent' => 250.0],
        'Courier'               => ['widths' => 'COURIER', 'ascent' => 629, 'descent' => 157, 'gap' => 347, 'xheight' => 426, 'capheight' => 562, 'marker' => 754, 'realAscent' => 753.90625, 'realDescent' => 246.09375],
        'Courier-Bold'          => ['widths' => 'COURIER', 'ascent' => 629, 'descent' => 157, 'gap' => 347, 'xheight' => 439, 'capheight' => 562, 'marker' => 754, 'realAscent' => 753.90625, 'realDescent' => 246.09375],
        'Courier-Oblique'       => ['widths' => 'COURIER', 'ascent' => 629, 'descent' => 157, 'gap' => 347, 'xheight' => 426, 'capheight' => 562, 'marker' => 754, 'realAscent' => 753.90625, 'realDescent' => 246.09375],
        'Courier-BoldOblique'   => ['widths' => 'COURIER', 'ascent' => 629, 'descent' => 157, 'gap' => 347, 'xheight' => 439, 'capheight' => 562, 'marker' => 754, 'realAscent' => 753.90625, 'realDescent' => 246.09375],

        /*
         * The two aliases, laid out with the metrics of the macOS faces Chrome
         * uses for them and still written into the PDF under the base-14 name
         * that stands in for the glyphs. `pdf` is what reaches `/BaseFont`, so
         * the document keeps naming a face every viewer has; the numbers beside
         * it are the real `hhea` and `OS/2` fields of the installed face, read
         * at 2048 units per em and scaled to 1000.
         *
         * `adjust` is false on both: Blink's 15 percent ascent correction
         * exists to line macOS's own Helvetica, Times and Courier up with the
         * Microsoft faces the web is written against, and these two ARE the
         * Microsoft faces, so Chrome applies nothing to them. Measured, not
         * assumed: with the correction on, `Courier New` misses every one of
         * the 29 sizes.
         *
         * The width tables stay the Adobe ones, because the glyphs a viewer
         * draws are still the base-14 face named in `pdf`. This pair fixes
         * where the line box and the baseline go, which is what DM and HV are
         * about, and changes no advance.
         */
        'Times New Roman'             => ['widths' => 'TIMES', 'pdf' => 'Times-Roman', 'adjust' => false, 'realGap' => 42.48046875, 'ascent' => 891, 'descent' => 216, 'gap' => 42, 'xheight' => 447, 'capheight' => 662, 'marker' => 891, 'realAscent' => 891.11328125, 'realDescent' => 216.30859375],
        'Times New Roman-Bold'        => ['widths' => 'TIMES_BOLD', 'pdf' => 'Times-Bold', 'adjust' => false, 'realGap' => 42.48046875, 'ascent' => 891, 'descent' => 216, 'gap' => 42, 'xheight' => 457, 'capheight' => 662, 'marker' => 891, 'realAscent' => 891.11328125, 'realDescent' => 216.30859375],
        'Times New Roman-Italic'      => ['widths' => 'TIMES_ITALIC', 'pdf' => 'Times-Italic', 'adjust' => false, 'realGap' => 42.48046875, 'ascent' => 891, 'descent' => 216, 'gap' => 42, 'xheight' => 430, 'capheight' => 662, 'marker' => 891, 'realAscent' => 891.11328125, 'realDescent' => 216.30859375],
        'Times New Roman-BoldItalic'  => ['widths' => 'TIMES_BOLD_ITALIC', 'pdf' => 'Times-BoldItalic', 'adjust' => false, 'realGap' => 42.48046875, 'ascent' => 891, 'descent' => 216, 'gap' => 42, 'xheight' => 439, 'capheight' => 662, 'marker' => 891, 'realAscent' => 891.11328125, 'realDescent' => 216.30859375],
        'Courier New'                 => ['widths' => 'COURIER', 'pdf' => 'Courier', 'adjust' => false, 'realGap' => 0.0, 'ascent' => 833, 'descent' => 300, 'gap' => 0, 'xheight' => 423, 'capheight' => 571, 'marker' => 833, 'realAscent' => 832.51953125, 'realDescent' => 300.29296875],
        'Courier New-Bold'            => ['widths' => 'COURIER', 'pdf' => 'Courier-Bold', 'adjust' => false, 'realGap' => 0.0, 'ascent' => 833, 'descent' => 300, 'gap' => 0, 'xheight' => 443, 'capheight' => 592, 'marker' => 833, 'realAscent' => 832.51953125, 'realDescent' => 300.29296875],
        'Courier New-Oblique'         => ['widths' => 'COURIER', 'pdf' => 'Courier-Oblique', 'adjust' => false, 'realGap' => 0.0, 'ascent' => 833, 'descent' => 300, 'gap' => 0, 'xheight' => 423, 'capheight' => 571, 'marker' => 833, 'realAscent' => 832.51953125, 'realDescent' => 300.29296875],
        'Courier New-BoldOblique'     => ['widths' => 'COURIER', 'pdf' => 'Courier-BoldOblique', 'adjust' => false, 'realGap' => 0.0, 'ascent' => 833, 'descent' => 300, 'gap' => 0, 'xheight' => 443, 'capheight' => 592, 'marker' => 833, 'realAscent' => 832.51953125, 'realDescent' => 300.29296875],
    ];

    /**
     * Blink's own ascent adjustment for the three base-14 families, as a
     * multiple of the font size.
     *
     * It applies to Times, Helvetica and Courier and to nothing else, which is
     * exactly the membership of the table above, so the table and the quirk
     * have the same shape. Round 52 measured it on a marker box and this class
     * spends it on the font box.
     */
    private const float ASCENT_ADJUST = 0.15;

    public function __construct(
        public readonly string $name = 'Helvetica',
        public readonly bool $bold = false,
        // Which slot this face fills, so the per-character fallback can reach
        // for the bundled face that matches it. The name says the same thing
        // for these twelve faces, but reading it back out of a name is a rule
        // that does not hold for the other face class, and both have to answer
        // this question the same way.
        public readonly bool $italic = false,
    ) {}

    /**
     * The bundled fallback in force, or null when there is none.
     *
     * A base-14 face is never itself a fallback face, so this is the active one
     * whenever one is installed.
     */
    private function fallback(): ?FontFallback
    {
        return FontFallback::active();
    }

    /**
     * Whether this face can draw a character at all.
     *
     * For a base-14 face that is exactly the question WinAnsi answers: the
     * encoding has 224 slots and the face has nothing outside them.
     */
    public function carries(int $codepoint): bool
    {
        return self::winAnsiByte($codepoint) !== null;
    }

    private ?array $widthCache = null;

    /**
     * A face this class does not know measures as Helvetica, which is what it
     * did for every face before the others existed. Silently proportional
     * metrics for a monospaced family was the bug; falling back for a name
     * nobody registered is not.
     *
     * @return array{
     *     widths: string,
     *     ascent: int,
     *     descent: int,
     *     gap: int,
     *     xheight: int,
     *     marker: int,
     *     realAscent: float,
     *     realDescent: float
     * }
     */
    private function face(): array
    {
        return self::FACES[$this->name]
            ?? self::FACES[$this->bold ? 'Helvetica-Bold' : 'Helvetica'];
    }

    /**
     * The face name to write into `/BaseFont`.
     *
     * It is the key itself for the twelve base-14 faces. The two alias
     * families carry a `pdf` entry instead, because they are laid out with the
     * metrics of the face Chrome uses and drawn with the glyphs of the base-14
     * face that stands in for it, and a PDF may only name one of the two.
     */
    public function pdfName(): string
    {
        return $this->face()['pdf'] ?? $this->name;
    }

    private function widths(): array
    {
        return $this->widthCache ??= constant(self::class . '::' . $this->face()['widths']);
    }

    /** @var array<string,float> memoised advance widths, keyed by text+size */
    private array $measured = [];

    public function ascent(float $size): float
    {
        return $this->face()['ascent'] * $size / 1000.0;
    }

    public function descent(float $size): float
    {
        return $this->face()['descent'] * $size / 1000.0;
    }

    /**
     * The band this face's own box covers, above and below the baseline.
     *
     * It is what an inline background paints over, what `vertical-align`
     * measures a parent's box by, and what a decoration is anchored on.
     *
     * **Both edges are whole CSS pixels, and the upper one is two terms
     * rounded apart**: `round(a * px) + round(0.15 * px)`, half away from zero,
     * where `a` is the face's real ascent. Rounding the sum instead misses
     * Helvetica at 16, 32, 44, 50 and 56 CSS pixels and Times at 18, 26, 30,
     * 38, 46 and 50, and those eleven bands are what says the two terms are
     * separate. `SZ-base14-inlinebg.html` is **33 of 33 against Chrome** over
     * the three families and seventeen sizes, where the pre-round engine is
     * 0 of 33. Defect GC.
     *
     * This spent half the normal leading on each side of the FITTED pair,
     * which is a different question's answer: that pair is where Chrome puts
     * the line box, and it happens to land within four hundredths of a pixel
     * of the right number at 12px and 24px, which is why the two Helvetica
     * control bands of `SW-face-inlinebg.html` never said anything.
     *
     * {@see TrueTypeFont::fontBox()} is the same shape for an embedded face:
     * the face's own numbers, each rounded to a whole CSS pixel, with no
     * adjustment term because Blink applies that to these three families only.
     *
     * **What no band here can separate** is whether the lower edge is
     * `round(d * px)` or the whole normal line box minus the upper edge. The
     * two agree on all thirty-three, because each real pair sums to 1000.
     *
     * @return array{0:float,1:float}
     */
    public function fontBox(float $size): array
    {
        $face = $this->face();
        $px   = $size / self::CSS_PIXEL;

        $adjust = ($face['adjust'] ?? true) ? round(self::ASCENT_ADJUST * $px) : 0.0;

        return [
            (round($face['realAscent'] / 1000.0 * $px) + $adjust) * self::CSS_PIXEL,
            round($face['realDescent'] / 1000.0 * $px) * self::CSS_PIXEL,
        ];
    }

    /**
     * Where a line box built from this face puts its baseline, and how far it
     * reaches below it, for a used `line-height` in points.
     *
     * **It is {@see fontBox}'s pair and the half-leading floored to a whole CSS
     * pixel**, which is exactly what {@see TrueTypeFont::lineBand} does for an
     * embedded face and is why an embedded face agreed with Chrome here while a
     * base-14 face did not. This spent half the leading on each side of the
     * FITTED `ascent`/`descent` pair instead, and that pair is a fit to where
     * Chrome puts the baseline rather than a metric, so it lands within about a
     * pixel at every size and on it at almost none. Defect DQ's residual, which
     * `sweep-verdicts.md` carries as bullet 13.
     *
     * The reading is `PY-family-baseline.html` through
     * `build/probe/layout-reference.py`, which is Chrome's own
     * `getBoundingClientRect` rather than a raster: **29 sizes on each of
     * Helvetica, Times and Courier, and all 87 are exact** where the fitted pair
     * was 0 of 87 on the nose. `SZ-marker-rounding.html`'s two Helvetica
     * residuals go with them, 8.95 CSS pixels where Chrome has 10 and 14.53
     * where it has 14.
     *
     * @return array{0:float,1:float}
     */
    public function lineBand(float $size, float $lineHeight): array
    {
        [$ascent, $descent] = $this->fontBox($size);

        $half  = ($lineHeight - ($ascent + $descent)) / 2.0;
        $above = $ascent + floor($half / self::CSS_PIXEL + 1e-9) * self::CSS_PIXEL;

        return [$above, $lineHeight - $above];
    }

    /** What `line-height: normal` resolves to, as a multiple of the font size. */
    public function normalLineHeight(): float
    {
        $face = $this->face();

        return ($face['ascent'] + $face['descent'] + $face['gap']) / 1000.0;
    }

    /** One CSS pixel in points, which is the grid a line box lands on. */
    private const float CSS_PIXEL = 0.75;

    /**
     * The height of a `line-height: normal` line box, quantized the way Chrome
     * quantizes it. Defect DQ.
     *
     * **A line box is a whole number of CSS pixels**, measured across 97 font
     * sizes on `RD-normal-lineheight-sweep.html`, and the error is otherwise
     * the same sign at a given size for every line on the page, so it
     * accumulates down a table rather than cancelling.
     *
     * **It is the face's own box and nothing else**, which is
     * {@see fontBox}'s two rounded terms added up. A `line-height: normal` line
     * box on these three faces has no leading at all: the real ascent and
     * descent sum to one em on Helvetica, Times and Courier alike, and Chrome's
     * 15 percent adjustment goes on the ascent, so the box IS the line.
     *
     * The reading is `PY-family-baseline.html` through Chrome's own
     * `getBoundingClientRect`: **29 sizes on each of the three families and all
     * 87 exact**. Rounding a fitted total instead landed 65 of 97 on
     * `RD-normal-lineheight-sweep.html`, which is what the 808/117/224 triple
     * was for and is a fit rather than a set of metrics.
     */
    public function lineSpacing(float $size): float
    {
        [$above, $below] = $this->fontBox($size);

        return $above + $below + $this->gapTerm($size);
    }

    /**
     * The line gap this face contributes to a `line-height: normal` box, as a
     * whole CSS pixel.
     *
     * It is zero for the twelve base-14 faces: their real ascent and descent
     * sum to one em and Chrome's 15 percent adjustment is what opens the line
     * up, so their `gap` field is a fit to that and adding it as well would
     * count the leading twice. A face carrying its own `hhea` gap is the other
     * case, and Chrome rounds the third term on its own: `round(ascent)` plus
     * `round(descent)` plus `round(gap)` is `Times New Roman`'s line box at
     * **29 of 29 sizes**, where folding the gap into either of the other two
     * is 20 and 23.
     */
    private function gapTerm(float $size): float
    {
        $face = $this->face();

        if ($face['adjust'] ?? true) {
            return 0.0;
        }

        return round(($face['realGap'] ?? 0.0) / 1000.0 * ($size / self::CSS_PIXEL)) * self::CSS_PIXEL;
    }

    /**
     * What a browser adds to one of these three faces' ascent, as a fraction
     * of the font size.
     *
     * Chrome carries a 15 percent adjustment for **Times, Helvetica and
     * Courier alone**, which is the twelve base-14 entries and not the two
     * alias families added in round 85, which carry `adjust => false` because
     * they ARE the Microsoft faces this exists to line up with. To make macOS's
     * own faces line up with the Microsoft ones the web is written against. It
     * is 15 percent of the ascent plus the descent, and those sum to one em on
     * all three, so it is 15 percent of the font size.
     */
    private const float MARKER_ADJUSTMENT = 0.15;

    /**
     * The ascent a list marker's shape is a fraction of, in whole CSS pixels.
     *
     * **Not the ascent a line box is built from**, which is the fitted number
     * in `FACES` and is a baseline correction rather than a metric. The bullet
     * comes off the face's real ascent plus the adjustment above, and the two
     * terms are rounded separately: `SR-marker-base14.html` and its three
     * sweeps are 18 sizes each on Helvetica, Times, Courier and two bold
     * faces, and rounding the sum instead misses 16px and 48px on Helvetica.
     * {@see BoxPainter::paintMarkerShape}.
     */
    public function pixelAscent(float $size): float
    {
        $px = $size / self::CSS_PIXEL;

        $face   = $this->face();
        $adjust = ($face['adjust'] ?? true) ? round(self::MARKER_ADJUSTMENT * $px) : 0.0;

        return round($face['marker'] * $px / 1000.0) + $adjust;
    }

    /**
     * The height of a lowercase `x`, which is the AFM's own `XHeight` field.
     *
     * CSS 2.1 §10.8.1 aligns a `vertical-align: middle` box against the
     * parent's baseline plus half of this, and it is the one metric the table
     * did not carry, which is what left defect AF unfixable until the split
     * of round 21 rewrote the table anyway.
     */
    public function xHeight(float $size): float
    {
        return $this->face()['xheight'] * $size / 1000.0;
    }

    /**
     * The metric `font-size-adjust` holds constant, in the same units as the
     * size, so the caller's aspect is this over the size.
     *
     * CSS Fonts 5 section 5.3 names five and the engine can answer four of them
     * off these faces. `capheight` is the AFM's own `CapHeight` field, from the
     * same Adobe files the width tables were generated from, and the two `ic`
     * metrics are one em because no base-14 face carries U+6C34 at all, which
     * is the fallback the spec asks for rather than a gap in the table.
     */
    public function sizeAdjustMetric(string $metric, float $size): float
    {
        return match ($metric) {
            'cap-height'            => $this->face()['capheight'] * $size / 1000.0,
            'ch-width'              => $this->stringWidth('0', $size),
            'ic-width', 'ic-height' => $size,
            default                 => $this->xHeight($size),
        };
    }

    /**
     * How far the font's own box reaches above the baseline: the ascent plus
     * half the line gap. It is the number a browser calls the font's ascent,
     * the one `line-height: normal` puts the baseline at, and it does not move
     * with a declared `line-height` the way a line box's half-leading does.
     *
     * `text-decoration` is placed from this rather than from the ascent alone,
     * which is what defect DN was.
     *
     * **This is {@see fontBox()} spelled out**, and the two were the same
     * arithmetic in two places until round 58 folded them together. The base-14
     * numbers do not move: for these faces the ascent plus half the leading and
     * the font box ARE one expression, which is not true of an embedded face.
     */
    public function boxAscent(float $size): float
    {
        return $this->fontBox($size)[0];
    }

    /** The other half of the font box: the descent plus half the line gap. */
    public function boxDescent(float $size): float
    {
        return $this->fontBox($size)[1];
    }

    /**
     * Code points WinAnsi puts somewhere other than their Latin-1 position.
     * Everything else in 0x20-0x7E and 0xA0-0xFF encodes as itself.
     */
    private const array WIN_ANSI = [
        0x20AC=>128, 0x201A=>130, 0x0192=>131, 0x201E=>132, 0x2026=>133,
        0x2020=>134, 0x2021=>135, 0x02C6=>136, 0x2030=>137, 0x0160=>138,
        0x2039=>139, 0x0152=>140, 0x017D=>142, 0x2018=>145, 0x2019=>146,
        0x201C=>147, 0x201D=>148, 0x2022=>149, 0x2013=>150, 0x2014=>151,
        0x02DC=>152, 0x2122=>153, 0x0161=>154, 0x203A=>155, 0x0153=>156,
        0x017E=>158, 0x0178=>159,
    ];

    /**
     * The WinAnsi byte for a code point, or null when the encoding has no
     * slot for it. A base-14 face can carry nothing else, so measurement and
     * output both resolve through here; otherwise they disagree about every
     * character above ASCII.
     */
    public static function winAnsiByte(int $codepoint): ?int
    {
        if ($codepoint < 0x7F) {
            return $codepoint;
        }

        if (isset(self::WIN_ANSI[$codepoint])) {
            return self::WIN_ANSI[$codepoint];
        }

        return $codepoint >= 0xA0 && $codepoint <= 0xFF ? $codepoint : null;
    }

    /**
     * Advance width of a string at a given font size, in points.
     *
     * The text is UTF-8, so it is decoded to code points first. Iterating
     * bytes measures `é` as two characters, which breaks every line holding
     * one early and throws off centring.
     *
     * A base-14 face carries no `GSUB`, `GPOS` or `kern` and is written with a
     * simple font encoding, so the feature set a run resolved is accepted and
     * ignored: the two font classes stay one interface to everything that
     * measures.
     */
    public function stringWidth(string $text, float $size, string $features = ''): float
    {
        $key = $size . "\0" . $text;

        if (isset($this->measured[$key])) {
            return $this->measured[$key];
        }

        $segments = $this->fallback()?->segments($this, $text);

        if ($segments === null) {
            return $this->measured[$key] = $this->ownWidth($text, $size);
        }

        $total = 0.0;

        foreach ($segments as [$face, $part]) {
            $total += $face === $this
                ? $this->ownWidth($part, $size)
                : $face->stringWidth($part, $size, $features);
        }

        return $this->measured[$key] = $total;
    }

    /**
     * This face's own advance for a piece of text, with no fallback in it.
     *
     * **A character this encoding cannot carry still measures as '?' here**,
     * which is deliberate: {@see FontFallback::segments()} hands this method
     * only the characters this face draws, and the ones it leaves behind are
     * the ones nothing at all can draw. Those are still painted as '?', so
     * that is still what they have to measure as.
     */
    private function ownWidth(string $text, float $size): float
    {
        $w     = $this->widths();
        $total = 0;

        foreach (TrueTypeFont::codepoints($text) as $cp) {
            $total += $w[self::winAnsiByte($cp) ?? 63] ?? 556;
        }

        return $total * $size / 1000.0;
    }

    /**
     * One glyph per character, since nothing here substitutes.
     *
     * A run that reaches the fallback for part of itself asks the face that
     * draws each piece, because `Tc` lands after every glyph the writer SHOWS
     * and the fallback is a shaping face where a base-14 one is not.
     */
    public function glyphCount(string $text, string $features): int
    {
        $segments = $this->fallback()?->segments($this, $text);

        if ($segments === null) {
            return count(TrueTypeFont::codepoints($text));
        }

        $count = 0;

        foreach ($segments as [$face, $part]) {
            $count += $face === $this
                ? count(TrueTypeFont::codepoints($part))
                : $face->glyphCount($part, $features);
        }

        return $count;
    }

    /** A base-14 face registers no OpenType feature at all. */
    public function featureTags(): array
    {
        return [];
    }

    /**
     * Greedy line breaking at word boundaries, the same algorithm
     * browsers use for `overflow-wrap: normal`.
     *
     * @return string[]
     */
    public function wrap(string $text, float $size, float $maxWidth): array
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];

        if ($words === [] || $words === ['']) {
            return [];
        }

        $lines   = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;

            if ($current !== '' && $this->stringWidth($candidate, $size) > $maxWidth) {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $candidate;
            }
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }

    /** Max intrinsic width: the longest unbreakable run. */
    public function maxContentWidth(string $text, float $size): float
    {
        return $this->stringWidth(trim($text), $size);
    }

    /** Min intrinsic width: the widest single word. */
    public function minContentWidth(string $text, float $size): float
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $max   = 0.0;

        foreach ($words as $word) {
            $max = max($max, $this->stringWidth($word, $size));
        }

        return $max;
    }
}
