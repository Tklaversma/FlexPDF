<?php
declare(strict_types=1);

namespace FlexPDF\Engine;

/**
 * Word breaking for justified and narrow text.
 *
 * Two mechanisms. A soft hyphen (U+00AD) is an explicit break the author put
 * there, and is always honored. `hyphens: auto` uses Liang's algorithm (the
 * one TeX uses) against a compact English pattern set: patterns carry odd
 * digits at legal break points and even digits at illegal ones, the highest
 * value at each position wins, and odd wins mean "break here".
 *
 * The pattern set here is a working subset, not the full TeX table. It breaks
 * common English words correctly and errs toward not breaking when unsure,
 * which is the safe direction: a missed hyphen is invisible, a wrong one is
 * not.
 */
final class Hyphenator
{
    public const string SOFT_HYPHEN = "\u{00AD}";

    /** Minimum characters to leave on each side of a break. */
    private const int LEFT_MIN  = 2;
    private const int RIGHT_MIN = 3;

    /**
     * Liang patterns. A leading `.` anchors to the word start, a trailing `.`
     * to the end. Digits sit between letters and score that position.
     */
    private const array PATTERNS = [
        '.ach4', '.ad4der', '.af1t', '.al3t', '.am5at', '.an5c', '.ang4', '.ani5m',
        '.ant4', '.an3te', '.anti5s', '.ar5s', '.ar4tie', '.ar4ty', '.as3c', '.as1p',
        '.as1s', '.aster5', '.atom5', '.au1d', '.av4i', '.awn4', '.ba4g', '.ba5na',
        '.bas4e', '.ber4', '.be5ra', '.be3sm', '.be5sto', '.bri2', '.but4ti', '.cam4pe',
        '.can5c', '.capa5b', '.car5ol', '.ca4t', '.ce4la', '.ch4', '.chill5i', '.ci2',
        '.cit5r', '.co3e', '.co4r', '.cor5ner', '.de4moi', '.de3o', '.de3ra', '.de3ri',
        '.des4c', '.dictio5', '.do4t', '.du4c', '.dumb5', '.earth5', '.eas3i', '.eb4',
        '.eer4', '.eg2', '.el5d', '.el3em', '.enam3', '.en3g', '.en3s', '.eq5ui5t',
        '.er4ri', '.es3', '.eu3', '.eye5', '.fes3', '.for5mer', '.ga2', '.ge2',
        '.gen3t4', '.ge5og', '.gi5a', '.gi4b', '.go4r', '.hand5i', '.han5k', '.he2',
        '.hero5i', '.hes3', '.het3', '.hi3b', '.hi3er', '.hon5ey', '.hon3o', '.hov5',
        '.id4l', '.idol3', '.im3m', '.im5pin', '.in1', '.in3ci', '.ine2', '.in2k',
        '.in3s', '.ir5r', '.is4i', '.ju3r', '.la4cy', '.la4m', '.lat5er', '.lath5',
        '.le2', '.leg5e', '.len5t', '.le5o', '.les2', '.lim5b', '.lin5e', '.lith5',
        '.love4', '.mal5o', '.man5a', '.mar5ti', '.me2', '.mer3c', '.me5ter', '.mis1',
        '.mist5i', '.mon3e', '.mo3ro', '.mu5ta', '.muta5b', '.ni4c', '.od2', '.odd5',
        '.of5te', '.or5ato', '.or3c', '.or1d', '.or3t', '.os3', '.os4tl', '.oth3',
        '.out3', '.ped5al', '.pe5te', '.pe5tit', '.pi4e', '.pio5n', '.pi2t', '.pre3m',
        '.ra4c', '.ran4t', '.ratio5na', '.ree2', '.re5mit', '.res2', '.re5stat',
        '.ri4g', '.rit5u', '.ro4q', '.ros5t', '.row5d', '.ru4d', '.sci3e', '.self5',
        '.sell5', '.se2n', '.se5rie', '.sh2', '.si2', '.sing4', '.st4', '.sta5bl',
        '.sy2', '.ta4', '.te4', '.ten5an', '.th2', '.ti2', '.til4', '.tim5o5', '.ting4',
        '.tin5k', '.ton4a', '.to4p', '.top5i', '.tou5s', '.trib5ut', '.un1a', '.un3ce',
        '.under5', '.un1e', '.un5k', '.un5o', '.un3u', '.up3', '.ure3', '.us5a',
        '.ven4de', '.ve5ra', '.wil2', '.ye4',
        'a5b', 'ab5i', 'a5ce', 'ach4', 'ac5tic', 'ad4d', 'ad3er', 'a5gi', 'ag4n',
        'a4l1a', 'al3en', 'al1i', 'al4ia', 'al5lev', 'al3m', 'a5log', 'a4ly', 'am5ab',
        'am3i', 'an5age', 'an3ar', 'an3est', 'an4gl', 'a5nia', 'an4im', 'an4it', 'ans5v',
        'an4te', 'an3u', 'ar5ap', 'ar3at', 'ar5b', 'ar3d', 'ar4ds', 'ar5ee', 'ar3i',
        'ar5ial', 'ar5ily', 'ar5m', 'ar5o', 'ar3q', 'as5ab', 'as4c', 'as5ph', 'as4sh',
        'as1t', 'a5ta', 'a5tel', 'at5en', 'a5tia', 'at5ic', 'at3ic4a', 'a5tin', 'at3it',
        'a5tiv', 'a5tor', 'at5r', 'au3b', 'au3r', 'av5ag', 'av3er', 'a5v4i', 'aw4ly',
        'ay5al', 'ba4ge', 'bal1a', 'ban5i', 'bar5b', 'be3lo', 'be5n', 'ber5n', 'be5sti',
        'bi4d', 'bil5i', 'bio5m', 'bi3ou', 'bi4t', 'b5itz', 'bl4', 'blath5', 'b4le',
        'bod3i', 'bol3i', 'bon5a', 'bor5d', 'bo5tan', 'bri2', 'brit4', 'buff5er',
        'bu4n', 'bunt4i', 'bus5i', 'but4ti', 'ca4in', 'ca5lat', 'cal4la', 'ca5nis',
        'ca5no', 'cant5er', 'ca5per', 'car5om', 'cast5er', 'cat5ac', 'cath5', 'cav5al',
        'c3c', 'ce4la', 'cel4', 'cen5ci', 'cen4t', 'cen5te', 'ch4', 'cha5o', 'chi2',
        'ci2', 'cif3i', 'cig3a', 'cin5q', 'cit5r', 'ck1', 'c2l', 'clar5at', 'co5ag',
        'coe2', 'co4gr', 'col5or', 'com5er', 'con5g', 'con5t', 'co3pa', 'cop3ic',
        'cor5b', 'cows4', 'cra5n', 'cred4', 'cre4v', 'cri5f', 'c4rin', 'cri5ti', 'cru4d',
        'c4ry', 'c4t', 'cu5pi', 'cus5is', 'cy4l', 'cze2', 'd2', 'd4a', 'd3d4', 'de5aw',
        'de5b', 'de4mo', 'de1n', 'de4pu', 'der5s', 'des4c', 'de1s4o', 'de3sq', 'de3st',
        'de3vi', 'de3vo', 'di4ct', 'di4er', 'di3ge', 'di4lato', 'di1re', 'dis1', 'di4t',
        'do5nat', 'doth4', 'd4r', 'du2', 'du4c', 'du4g', 'd5umbl', 'dur4', 'e1a4',
        'e3act', 'ead1', 'eal5er', 'eam3er', 'e5and', 'ear3a', 'ear4c', 'ear5es',
        'ear4ic', 'ea2t', 'eath3i', 'e5atif', 'e4au', 'eav3en', 'eav5i', 'eav5o',
        'e5b', 'e4bel', 'e1c', 'ec5ca', 'ech4', 'e5chan', 'e5cip', 'ec5ita', 'e4cite',
        'e2d', 'ed3d', 'ed1i', 'e3dia', 'ed3ib', 'ed3ica', 'ed3im', 'ed1it', 'edi5z',
        'e5du', 'ed5uc', 'e4f', 'e4fic', 'e5for', 'e4fu', 'e4g', 'eg5ic', 'eg5ing',
        'e5git5', 'eg5n', 'e4g3o', 'e1h4', 'e3ic', 'ei5d', 'eig2', 'ei5gl', 'e3imb',
        'e3inf', 'e1ing', 'e5inst', 'eir4d', 'eit3e', 'ei2t', 'e5ity', 'e1j', 'e4jud',
        'ek4l', 'e4kno', 'ekof4', 'e1la', 'el3ativ', 'el14', 'el3ega', 'e5len', 'e4l1er',
        'e1les', 'el2f', 'el2i', 'e3libe', 'e4l5ic1', 'el3ica', 'e3lier', 'el5igib',
        'e5lim', 'e4l3ing', 'e3lio', 'e2lis', 'el5ish', 'e3liv3', 'e2ll', 'el4la',
        'em3a', 'em5ana', 'e4mel', 'e1men', 'em3i', 'e5mie', 'em5igra', 'em1in2',
        'em5ine', 'em3i3ni', 'e4mis', 'em5ish', 'e5miss', 'em3iz', 'e4mo', 'em3or',
        'em3p', 'e4n', 'en5agi', 'en5alt', 'en3ate', 'en4berg', 'en5ces', 'en3d',
        'en3dic', 'e5nea', 'e5nee', 'en3em', 'en5ero', 'en5esi', 'en5est', 'en3etr',
        'e3new', 'en5ics', 'e5nie', 'e5nil', 'e3nio', 'en3ish', 'en3it', 'e5niu',
        'en3kero', 'en4dic', 'e4n3o', 'en3oi', 'e4nos', 'en3ov', 'en4sw', 'ent5ag',
        'e5nthes', 'en3ua', 'en5uf', 'e3ny', 'en3z', 'e5of', 'eo2g', 'e4oi4', 'e3ol',
        'eop3ar', 'e1or', 'eo3re', 'eo5rol', 'eos4', 'e4ot', 'eo4to', 'e5out', 'e5ow',
        'e2pa', 'e3pai', 'ep5anc', 'e5pel', 'e3pent', 'ep5etitio', 'ephe4', 'e4pli',
        'e1po', 'e4prec', 'ep5reca', 'e4pred', 'ep3reh', 'e3pro', 'e4prob', 'ep4sh',
        'ep5ti5b', 'e4put', 'ep5uta', 'e1q', 'equi3l', 'e4q3ui3s', 'er1a', 'era4b',
        'er3ar', 'er4ba', 'er4blo', 'er4che', 'er4d', 'ere5co', 'ere3in', 'er5el',
        'er3emo', 'er5ena', 'er5ence', 'er3ent', 'ere4q', 'er5ess', 'er3est', 'eret4',
        'er1h', 'er1i', 'e1ria4', 'er4ick', 'e3rien', 'er3ies', 'er3ine', 'e1rio',
        'er4iu', 'er2l', 'er3m', 'er4nis', 'er3no', 'er5ob', 'e5roc', 'ero4r', 'er1ou',
        'er1s', 'er3set', 'ert3er', 'er3tl', 'er3tw', 'er4v', 'es5can', 'es5ca',
        'e2sc', 'es5o', 'e2sp', 'es3pir', 'es4pre', 'es2s', 'es4si', 'es5tan', 'es3tig',
        'es5tim', 'e3ston', 'e2su', 'es5urr', 'et3ic', 'e5tide', 'eti4no', 'e5tir',
        'e5titio', 'et5itiv', 'et5ri', 'et3ric', 'et5rif', 'e5tud', 'et5ym', 'e5typ',
        'eu3ro', 'eus4', 'eute4', 'e2v', 'ev3ast', 'ev5el', 'ev3er', 'ev5era', 'ev3id',
        'ev5il', 'e5vin', 'e5viv', 'e5voc', 'e5vu', 'e1x', 'ex5p', 'f2', 'fa4ke',
        'f4a1t', 'fen2d', 'fer5o', 'fic4i', 'f3ic1a', 'fi3del', 'fight5', 'fil5i',
        'fill5in', 'fin2d', 'fi2n', 'fis4ti', 'f4l2', 'flin4', 'flo3re', 'f4ly5',
        'for5mer', 'for5th', 'f4r', 'fu5min', 'fun5er', 'fu3ri', 'fus4s', 'fu5tili',
        'g2', 'ga2n', 'gar5n4', 'gass4', 'ga4z', 'g3b', 'gd4', 'ge2', 'ge4no',
        'ge5ni', 'ge5og', 'g4es', 'g3ger', 'gg5', 'gi4a', 'gi5na', 'gin5ge', 'g4n',
        'gn4a', 'g4no', 'go4r', 'g3p', 'gr4', 'gs2', 'g5ste', 'gth3', 'gu4t', 'g3w',
        'h2', 'ha5la', 'han4ci', 'han4cy', 'ha5o', 'hard3', 'har4le', 'harp5en',
        'has5s', 'haun4', 'he2n', 'he5ori', 'her4b', 'here5a', 'h5ers', 'hes3', 'h2i',
        'hi5an', 'hi4co', 'high5', 'h4il2', 'himer4', 'h4ina', 'hion4e', 'hi4p',
        'hir4l', 'ho5ge', 'hol5ar', 'ho4ma', 'hom5et', 'hon4a', 'ho5ny', 'h5o5rd',
        'ho5ris', 'ho5riz', 'hree5', 'hro5niz', 'hro3po', 'h4s2', 'hty5', 'hu4g',
        'hu4mi', 'i1a', 'i2al', 'iam4', 'iam5et', 'i2an', 'ia5pe', 'iass4', 'i4ativ',
        'ia4tric', 'i4atu', 'ibe4', 'ib3era', 'ib5ert', 'ib5ia', 'ib3in', 'ib5it',
        'i1c', 'i3cam', 'ice4', 'ich4', 'i5chi', 'ic4in', 'i3cip', 'ic3ipa', 'i4cly',
        'i2c5oc', 'i4cr', 'i3cra', 'ic5ula', 'ic4um', 'ic5uo', 'i3cur', 'i2d', 'id5ay',
        'ide4s', 'i2di', 'id5ian', 'idi4ar', 'i5die', 'id3io', 'idi5ou', 'id1it',
        'id5iu', 'i3dle', 'i4dom', 'id3ow', 'i4dr', 'i2du', 'id5uo', 'i2f', 'if4fr',
        'i3fie', 'i3fl', 'if2s', 'i1g', 'ig3era', 'ight3i', 'ig5il', 'ig3in', 'ig3it',
        'i4g4l', 'i2go', 'ig3or', 'ig5ot', 'i5gre', 'ig2u', 'i5guit', 'i4h', 'i5i',
        'i3j', 'i4l', 'il3a', 'il4ab', 'i4lade', 'i2l5am', 'ila5ra', 'i3leg', 'il1er',
        'ilev4', 'il5f', 'il1i', 'il3ia', 'il2ib', 'il3io', 'il4ist', 'i2l1it', 'il2iz',
        'ill5ab', 'i4lm', 'i16n', 'i3lo', 'il5oq', 'il4ty', 'il5ur', 'il3v', 'i4mag',
        'im3age', 'ima5ry', 'imenta5r', 'i4met', 'im1i', 'im5ida', 'imi5le', 'i5mini',
        'im4ni', 'i3mon', 'i2mu', 'im3ula', 'i2n', 'i3nao', 'in4au', 'in3ci', 'in5cl',
        'ine5', 'i4nes', 'in3f', 'ing3', 'in3gen', 'ing5l', 'in3io', 'in1is', 'i5nite',
        'in5itia', 'in3k', 'in3os', 'i4n3sa', 'in3se', 'insur5a', 'in3te', 'in3u',
        'i5o5n', 'io5ph', 'ior3i', 'i4os', 'i4ot', 'i4p', 'ip4ing', 'i3pl', 'ip3ul',
        'i3qua', 'iq5uef', 'iq3uid', 'iq3ui3t', 'i4r', 'i5ra', 'ira4b', 'i4rac',
        'ird5e', 'ire4de', 'i4ref', 'i4rel', 'i4res', 'ir5gi', 'ir1i', 'iri5de',
        'ir4is', 'iri3tu', 'ir4min', 'iro4g', 'ir5ul', 'i2s', 'is5ag', 'is3ar', 'isas5',
        'is1c', 'is3ch', 'is4der', 'ise5cr', 'is5er', 'is3est', 'is3f', 'ish5op',
        'is3ho', 'is4hi', 'is3ip', 'is3ki', 'is4l', 'is4p', 'is2s', 'is4sal', 'is5sen',
        'is4sl', 'is4sp', 'ist4', 'is4ta', 'is1te', 'is1ti', 'ist4ly', 'i5su', 'i4ta',
        'ita4bi', 'i4tag', 'i3tan', 'i3tat', 'i4tia', 'it3ic', 'i5tick', 'it3ig',
        'it5ill', 'i2tim', 'i2t1i2s', 'i5tiv', 'i5tor', 'i4tram', 'it5ry', 'i5tt',
        'itu4a', 'i5tud', 'it3ul', 'i1u', 'i2v', 'iv3ell', 'iv3en', 'i4v3er1', 'i4vers',
        'iv5il', 'iv5io', 'iv1it', 'i5vore', 'iv3o3ro', 'i4v3ot', 'i5w', 'ix4o', 'i2z',
        'j4', 'ja4p', 'j3d', 'je4', 'jer5s', 'jo4p', 'ju3r', 'k3b', 'k2ed', 'ke4g',
        'ke5li', 'k3en4d', 'k1er', 'kes4', 'k3est', 'ke4ty', 'k3f', 'kh4', 'k1i',
        'k5ish', 'kk4', 'k1l', 'k4nes', 'k5nesse', 'kn4o', 'ko5r', 'k3ou', 'kro5n',
        'k1s2', 'l4', 'l1a', 'la4b', 'lab4or', 'la4cy', 'la4de', 'la3dy', 'lag4n',
        'lam3o', 'l3an', 'lan4dl', 'lan5et', 'lan4te', 'lar4g', 'lar3i', 'las4e',
        'la5tan', 'la4v4a', 'l1b', 'lc4', 'l1d', 'l3dr', 'le2a', 'le4bi', 'left5',
        'le5gal', 'le5gis', 'le3git', 'leg5o', 'le3ma', 'lem5at', 'len5c', 'le3ni',
        'l5en5o', 'lep5er', 'l5e5pr', 'ler4e', 'ler5o', 'les2', 'l1f', 'l1g', 'l3ga',
        'lgar3', 'l4ger', 'lgo3', 'l1h', 'li4ag', 'li2am', 'liar5iz', 'li4as', 'li4ato',
        'li5bi', 'l5ic4io', 'li4cor', 'l4icu', 'l3icy', 'l3ida', 'lid5er', 'li3en',
        'l3if', 'li4fe', 'lig5a', 'lig3h', 'l4i5g5n', 'li4gra', 'l3ih', 'l4ik', 'l5ing',
        'li5og', 'li4p', 'li3qu', 'l3isi', 'li5te', 'l5i5tics', 'liv3er', 'l1l',
        'll4a', 'l4le', 'll5out', 'l2m', 'l4mo', 'lo4ci', 'l4o3du', 'lo4gan', 'log5ic',
        'l3o3niz', 'lood5', 'lo4pe', 'lop3i', 'l3opm', 'lo5rat', 'lor5ou', 'l5ou4r',
        'lp5ing', 'l3pha', 'l5phi', 'lp5ing', 'l3q', 'l1r', 'l1s2', 'l4sc', 'l2se',
        'l4sy', 'l1t', 'lt5ag', 'ltane5', 'l1ten', 'lten4', 'lth3i', 'l5ties', 'ltis4',
        'l1tr', 'ltu2', 'ltur3a', 'lu5a', 'lu3br', 'luch4', 'lu3ci', 'lu3en', 'luf4',
        'lu5id', 'lu4ma', 'l5umi', 'l4un', 'lu3o', 'luo3r', 'l1v', 'l1w', 'l1y',
        'm2', 'ma2c', 'ma5chine', 'ma4cl', 'mag5in', 'mal5o', 'man5a', 'man5u',
        'mar5ti', 'mas4e', 'mas1t', 'ma5tis', 'm1b', 'mb4i', 'm5bil', 'm4b3ing',
        'mbi4v', 'm1c', 'm1d', 'me4bi', 'me3gr', 'men4a', 'men5ac', 'men4de', 'me4ni',
        'men4i', 'mens4', 'mensu5', 'me3on', 'm5ersa', 'mes1', 'me4ter', 'm5etry',
        'me5thi', 'm1f', 'm2i', 'mi3a', 'mid4a', 'mid4g', 'mig4', 'mil4ti', 'm5ingly',
        'mi4nu', 'mi4ni', 'm5ish', 'm5istry', 'mi1z', 'm1m', 'mm1', 'mn4a', 'm4nin',
        'mn4o', 'm1p', 'mpa5ra', 'mpi4', 'mp5ies', 'm4p1in', 'm5pir', 'mp5is', 'mpo3ri',
        'mpos5ite', 'm4pt', 'm5py', 'm3r', 'm1s2', 'm1t', 'mu4dr', 'mu4l', 'mul5ti5u',
        'm5um', 'mu4nis', 'm5up', 'mu4u', 'mu3sic', 'm5ute', 'mu5ta', 'n1a', 'n5abl',
        'n4ac', 'na4ca', 'n5act', 'nad4i', 'na4li', 'na5lia', 'n5alities', 'na5mit',
        'n4ancy', 'n4ard', 'nar3c', 'nar3i', 'nar4l', 'n5arm', 'n4as', 'nas4c', 'nas5ti',
        'n2at', 'na3tal', 'nato5miz', 'n2au', 'nau3se', 'n1b4', 'nc4', 'n1c1e',
        'n3ces', 'nch4e', 'n3cin', 'n3cite', 'ncour5a', 'n1cr', 'n1cu', 'n4dai',
        'n5dan', 'n1de', 'nd5est', 'ndi4b', 'n5d2if', 'n1dit', 'n3diz', 'n5duc',
        'ndu4r', 'nd2we', 'n4eb', 'ne2b', 'ne4bu', 'ne2c', 'n5ecd', 'ne4gat', 'neg5ativ',
        'n5eme', 'ne4mo', 'n1en', 'nen4t', 'ne4po', 'ne4q', 'n1er', 'nera5b', 'n4erar',
        'n2ere', 'n4er5i', 'ner4r', 'n1es2', 'n4esp', 'n2est', 'n4esthe', 'ne5te',
        'ne4v', 'n5eve', 'ne4w', 'n3f', 'n4gab', 'n3gel', 'nge4n4e', 'n5gerin', 'n3ger',
        'n3gi', 'n1gl', 'n5gli', 'ngov4', 'ng5sh', 'n1gu', 'n4gum', 'n2gy', 'n1h4',
        'nha4', 'nhab3', 'nhe4', 'n3hi', 'n1hy', 'ni4ap', 'ni3ba', 'ni4bl', 'ni4d',
        'ni5di', 'ni4er', 'ni2fi', 'ni5ficat', 'n5igr', 'nik4', 'n1im', 'ni3miz',
        'n1in', 'nin4g', 'ni4o', 'n5ish', 'nis4ta', 'n2it', 'n4ith', 'n3itor', 'ni3tr',
        'n1j', 'n5kero', 'n3ket', 'nk5in', 'n1kl', 'n5f', 'n2l', 'n5m', 'nme4',
        'nmet4', 'n1n2', 'nne4', 'nni3al', 'nni4v', 'nob4l', 'no3ble', 'n5ocl', 'n4o2c',
        'no4bl', 'no3l', 'nom5e', 'no4mo', 'no3my', 'n1on', 'no4n', 'non4ag', 'no3nin',
        'n5oniz', 'n4oo', 'nop5y', 'no4pen', 'n5organ', 'n5o5rig', 'nor5m', 'n1o5tis',
        'no5vel', 'n1p4', 'npi4', 'npre4c', 'n1q', 'n1r', 'nry4', 'n4rw', 'ns2',
        'n3sa', 'n3sc', 'nsen5', 'ns5ic', 'n3sig', 'n4sl', 'ns3m', 'n4soc', 'ns4pe',
        'n5spi', 'nsta5bl', 'n1t', 'nta4b', 'nter3s', 'nt2i', 'n5tib', 'nti4er',
        'nti2f', 'n3tine', 'n4t3ing', 'nti4p', 'ntrol5li', 'nt4s', 'ntu3me', 'nu1a',
        'nu4d', 'nu5en', 'nuf4fe', 'n3uin', 'nu3mer', 'nu4t', 'n1v2', 'n1w4', 'nym4',
        'nyp4', 'n3za', 'n1z2', 'o5a', 'oad3', 'o5ard', 'oas4e', 'oast5e', 'oat5i',
        'ob3a3b', 'o5bar', 'obe4l', 'o1bi', 'o2bin', 'ob5ing', 'o3br', 'ob3ul',
        'o1ce', 'oc3ea', 'o3chet', 'ocif3', 'o4cil', 'o4clam', 'o4cod', 'oc3rac',
        'oc5ratiz', 'ocre3', 'o5cri', 'oc3ula', 'o4cure', 'od5ded', 'od3ic', 'odi3o',
        'o2do4', 'odor3', 'od5uct', 'o4el', 'o4er', 'oe4ta', 'o3eu', 'o2f', 'of5ite',
        'ofit4t', 'o2g5a5r', 'og5ativ', 'o4gato', 'o1ge', 'o5gene', 'o5geo', 'o4ger',
        'o3gie', 'og4it', 'o4gl', 'o5gly', 'o3gnia', 'og3on', 'og5ra', 'o4gu', 'o1h2',
        'ohab5', 'oi2', 'oic3es', 'oi3der', 'oiff4', 'oig4', 'oi5let', 'o3ing', 'oint5er',
        'o5ism', 'oi5son', 'oist5en', 'oi3ter', 'o5j', 'o1la', 'o4lan', 'olass4',
        'ol2d', 'old1e', 'ol3er', 'o3lesc', 'o3let', 'ol4fi', 'ol2i', 'o3lia', 'o3lice',
        'ol5id5a', 'o3li4f', 'o5lil', 'ol3ing', 'o5lio', 'o5lis4', 'ol3ish', 'o5lite',
        'o5litio', 'o5liv', 'olli4e', 'ol5ogiz', 'olo4r', 'ol5pl', 'ol2t', 'ol3ub',
        'ol3ume', 'ol3un', 'o5lus', 'ol2v', 'o2ly', 'om5ah', 'oma5l', 'om5atiz',
        'om2be', 'om4bl', 'o2me', 'om3ena', 'om5erse', 'o4met', 'om5etry', 'o3mia',
        'om3ic', 'om3ica', 'o5mid', 'om1in', 'o5mini', 'omm5as', 'om1me', 'o4mo',
        'o3mon', 'om3pi', 'ompro5', 'o2n', 'on1a', 'on4ac', 'o3nan', 'on1c', 'non4ag',
        'on5d', 'on5ic', 'o3nio', 'on1is', 'o5niu', 'on3key', 'on4odi', 'on3omy',
        'on3s', 'onspi4', 'onspir5a', 'onsu4', 'onten4', 'on3t4i', 'ontif5', 'on5um',
        'onva5', 'oo2', 'ood5e', 'ood5i', 'oo4k', 'oop3i', 'o3ord', 'oost5', 'o2pa',
        'ope5d', 'op1er', 'o3pha', 'o5phe', 'op3ing', 'o3pit', 'o5pon', 'o4posi',
        'o1pr', 'op1u', 'opy5', 'o1q', 'o1ra', 'o5ra4g', 'or5aliz', 'or5ange', 'ore5a',
        'o5real', 'or3ei', 'ore5sh', 'or5est', 'orew4', 'or4gu', 'o5rif', 'or4ia',
        'or3ic', 'o1rid', 'o5rio', 'or3ity', 'o3riu', 'or2mi', 'orn2e', 'o5rof',
        'or3oug', 'or5pe', 'or3ru', 'or4se', 'ors5en', 'orst4', 'or3thi', 'or3thy',
        'or4ty', 'o5rum', 'o1ry', 'os3al', 'os2c', 'os4ce', 'o3scop', 'os4i4e', 'os5itiv',
        'os3ito', 'os3ity', 'osi4u', 'os4l', 'o2so', 'os4pa', 'os4po', 'os2ta', 'o5stati',
        'os5til', 'os5tit', 'o4tan', 'otele4g', 'ot3er', 'ot5ers', 'o4tes', 'oth5esi',
        'oth3i4', 'ot3ic', 'ot5ica', 'o3tice', 'o3tif', 'o3tis', 'oto5s', 'ou2', 'ou3bl',
        'ouch5i', 'ou5et', 'ou4l', 'ounc5er', 'oun2d', 'ou5v', 'ov4en', 'over4ne',
        'over3s', 'ov4ert', 'o3vis', 'oviti4', 'o5v4ol', 'ow3der', 'ow3el', 'ow5est',
        'ow1i', 'own5i', 'o4wo', 'oy1a', 'p4', 'pa4ca', 'pa4ce', 'pac4t', 'p4ad',
        'pag4', 'p4ai', 'pain4', 'p4al', 'pan4a', 'pan3el', 'pan4ty', 'pa3ny', 'pa1p',
        'pa4pu', 'para5bl', 'par5age', 'par5di', 'p3aren', 'par5el', 'p4a4ri', 'par4is',
        'pa2te', 'pa5ter', 'path5i', 'p4atric', 'pav4', 'p4b', 'pd4', 'p3e', 'pe2',
        'pe5a', 'pear4l', 'pe2c', 'p2ed', 'pe3da', 'p3edi', 'pedia4', 'ped4ic', 'p4ee',
        'pee4d', 'pek4', 'pe4la', 'peli4e', 'pe4nan', 'p4enc', 'pen4th', 'pe5on',
        'p4era', 'pera5bl', 'p4erag', 'p4eri', 'peri5st', 'per6mal', 'perme5', 'p4ern',
        'per3o', 'per3ti', 'pe5ru', 'per1v', 'pe2t', 'pe5ten', 'pe5tiz', 'pf4', 'pg4',
        'ph2', 'phar5i', 'phe3no', 'ph4er', 'ph4es', 'ph1ic', 'ph5ing', 'phi5t',
        'p5hleg', 'ph3ly', 'pho4r', 'ph4s', 'ph3t', 'p5i4', 'pi3a', 'pian4', 'pi4cie',
        'pi4cy', 'p4id', 'p5ida', 'pi3de', 'pi2n', 'p4in', 'pind4', 'p4iness', 'pi2t',
        'pi5tha', 'pi3tu', 'p2l2', 'pla5c', 'plas5t', 'pli3a', 'pli5er', 'plig5', 'plu4m',
        'plum4b', 'p4m', 'p3n', 'po4c', 'po5em', 'po4et', 'p5oid', 'po4ly', 'po4p',
        'p4or', 'po4ry', 'p1p', 'ppa5ra', 'p2pe', 'p4pl', 'p4pr', 'pr2', 'pray4e',
        'p5reci', 'pre5co', 'pre3em', 'pref5ac', 'pre4la', 'pre3r', 'p3rese', 'pres5p',
        'pre5ten', 'pre3v', 'p3rio', 'pri4s', 'p3rob', 'p2ro', 'prof5it', 'pro3l',
        'pros3e', 'pro1t', 'p4s', 'p5sic', 'p4sy', 'p5t4', 'p2te', 'p2th', 'p1tu',
        'p4w', 'py5ram', 'q2', 'qu2', 'qua5v', 'q3ui3s', 'qu3o', 'r1a', 'ra3bi',
        'rach4e', 'r5acl', 'raf5fi', 'raf4t', 'r2ai', 'ra4lo', 'ram3et', 'r2ami',
        'rane5o', 'ran4ge', 'r4ani', 'ra5no', 'rap3er', 'r3aphy', 'rar5c', 'rare4',
        'rar5ef', 'r4as', 'ra5vai', 'rav3el', 'ra5zie', 'r1b', 'r4bab', 'r4bag',
        'rb2i', 'r2bin', 'r5bine', 'rb5ing', 'r3bl', 'r1c', 'r2ce', 'rcen4', 'r3cha',
        'r5chit', 'rcum3', 'r4dal', 'rd2i', 'rdi4a', 'rdi4er', 'rd3ing', 'r2dr',
        'r5dric', 'rd3sm', 're1a', 're3al', 'reap3', 'rear4r', 're5aw', 'r5ebrat',
        'rec5oll', 'rec5ompe', 're4cre', 'r4ed', 're5de', 're3dis', 'red5it', 're4fac',
        're2fe', 're5fer', 're3fi', 're4fy', 'reg3is', 're5it', 're1li', 're5lu', 'r4en',
        'ren4ta', 'ren4te', 're1o', 're5pin', 'rep3li', 're4posi', 're1pu', 'r1er4',
        'r4eri', 'rero4', 're5ru', 'r4es', 're4spi', 'ress5ib', 'res2t', 're5stal',
        're3str', 're4ter', 're4ti4z', 're3tri', 'reu2', 're5uti', 'rev2', 're4val',
        'rev3el', 'r5ev5er', 're5vers', 're5vert', 're5vil', 'rev5olu', 're4wh', 'r1f',
        'r3fu', 'r4fy', 'rg2', 'rg3er', 'r3get', 'r3gic', 'rgi4n', 'rg3ing', 'r5gis',
        'r5git', 'r1gl', 'rgo4n', 'r3gu', 'rh4', 'r1h4', 'ri3a', 'ria4b', 'ri4ag',
        'r4ib', 'rib3a', 'ric5as', 'r4ice', 'ri4cid', 'ri4la', 'ril3iz', 'ri1o',
        'r4iph', 'riph5e', 'ri2pl', 'rip5lic', 'r4iq', 'r2is', 'ris4c', 'r3ish',
        'ris4p', 'ri3ta3b', 'r5ited', 'rit3ic', 'ri5tu', 'rit5ur', 'riv5el', 'riv3et',
        'riv3i', 'r3j', 'r3ket', 'rk4le', 'rk4lin', 'r1l', 'rle4', 'r2led', 'r4lig',
        'r4lis', 'rl5ish', 'r3lo4', 'r1m', 'rma5c', 'r2me', 'r3men', 'rm5ers', 'rm3ing',
        'r4ming', 'r4mio', 'r3mit', 'r4my', 'r4nar', 'r3nel', 'r4ner', 'r5net', 'r3ney',
        'r5nic', 'r1nis4', 'r3nit', 'r3niv', 'rno4', 'r4nou', 'r3nu', 'rob3l', 'r2oc',
        'ro3cr', 'ro4e', 'ro1fe', 'ro5fil', 'rok2', 'ro5ker', 'r5ole', 'rom5ete',
        'rom4i', 'rom4p', 'ron4al', 'ron4e', 'ro5n4is', 'ron4ta', 'ro5pel', 'rop3ic',
        'ror3i', 'ros5per', 'ros4s', 'ro4th3', 'r5ou4f', 'rou5p', 'row5d', 'ro1v',
        'r1p', 'r4pea', 'r5pent', 'rp5er', 'r3pet', 'rp4h4', 'rp3ing', 'r3po', 'r1r4',
        'rre4c', 'rre4f', 'r4reo', 'rre4st', 'rri4o', 'rri4v', 'rron4', 'rros4',
        'rrys4', 'r1s2', 'r3sa', 'rsa5ti', 'rs4c', 'r2se', 'r3sec', 'rse4cr', 'rs5er',
        'rs3es', 'rse5v2', 'r1sh', 'r5sha', 'r1si', 'r4si4b', 'rson3', 'r1sp', 'r5sw',
        'rtach4', 'r4tag', 'r3teb', 'rten4d', 'rte5o', 'r1ti', 'rt5ib', 'rti4d', 'r4tier',
        'r3tig', 'rtil3i', 'rtil4l', 'r4tily', 'r4tist', 'r4tiv', 'r3tri', 'rtroph4',
        'rt4sh', 'ru3a', 'ru3e4l', 'ru3en', 'ru4gl', 'ru3in', 'rum3pl', 'ru2n', 'runk5',
        'run4ty', 'r5usc', 'ruti5n', 'rv4e', 'rvel4i', 'r3ven', 'rv5er5', 'r5vest',
        'r3vey', 'r3vic', 'rvi4v', 'r3vo', 'r1w', 'ry4c', 'ry3t', 'ry5t', 's2',
        's1ab', 's5ack', 'sa5lo', 'salt5', 'sa3lu', 'sanc5', 'sa5ta', 's3b', 'scan4t5',
        'sca4p', 'scav5', 's4ced', 's4cli', 'scof4', 's4cu', 's1d4', 'se4a', 'seas4',
        'se2c3o', 's4ect', 's4ed', 'se4d4e', 's5edl', 'se2g', 'seg3r', 'se1i', 'se5les',
        's4e1n', 's5enin', 'se5pa', 'sep3a3', 's4er', 'ser4l', 'ser4o', 's1e4s', 'se5um',
        'sev3en', 'sew4i', 's3f', 's2g', 's2h', 'sh1er', 's5hev', 'sh1in', 'sh3io',
        'sh3iv', 'sho4', 'sh5old', 'shon3', 'shor4', 'short5', 'si2b', 's5icc', 's4id',
        's1ing', 'si5nol', 's3ip', 's4ir', 'sis4t', 's5itio', 'si2u', 's1j', 's3ket',
        's5ki', 's1l2', 's2m', 'sma5c', 's1n4', 'so4ce', 'soft3', 'so4lab', 'sol3d2',
        'so3lic', 's5o5lu', 'so4los', 'som3e', 'so5phic', 's4or', 'so4vi', 's1p',
        's4pace', 's4pai', 's4pec', 's2pi', 's4por', 's4pot', 'squal4l', 's1r', 'ss4',
        's1sa', 'ssas3', 's2s5c', 's3sel', 's5seng', 's4ses', 's5set', 's1si', 'ss5ily',
        's4sl', 'ss4li', 's4sn', 'sspend4', 'ss2t', 'ssur5a', 'ss5w', 's1t', 's2tag',
        's2tal', 'stam4i', 's4ta4p', 'ste2', 'ste2r', 's5teri', 's4tes', 's4t3ic',
        's4tie', 's3tif', 'st3ing', 's4tir', 's1tle', 's4tom', 'st4r', 's4tru', 's2tu',
        's4ty', 's1u2', 'su1al', 'su4b3', 'su2g3', 'su5is', 'sui5t', 's4ul', 'su2m',
        'sum3i', 'su2n', 'su2r', 's1v', 'sw2', 's4wo', 's4y', 'syl5lab', 'sym5', 'syn5',
        't2', 't3ab', 'ta5bles', 't5ably', 'ta5lo', 't4am', 'ta4pl', 'tar4a', 't4arc',
        't4ares', 'ta5riz', 'tas4e', 'ta5sy', 't4atr', 't5ch', 'tch5et', 't4ck', 't2ed',
        't5een', 't5ella', 'tel2i', 't5eme', 'ten4ag', 't5enan', 't5ell', 'ten5tag',
        't1er', 'ter3c', 't5eri', 'ter5iz', 'ter3o', 'ter5v', 't4es', 'tes2', 't3ess',
        't3est', 't3f', 't1g', 'th2', 'th5ab', 'th3ach', 'thal5m', 'th5ic', 'th5ica',
        'th5ill', 'th5ing', 'th5od3', 'thol3i', 'th5ono', 'th4orit', 'tho5riz', 'th5s',
        'ti2', 'ti5ab', 'ti4ato', 't2ic', 't5ically', 'tic4ul', 'tic4a', 't5ida',
        'ti5di', 'ti5fy', 'ti2n', 't4ina', 'tin5t', 't4iq', 'ti3sa', 't5ish', 'ti4spe',
        'tiv4a', 't5ivity', 'ti3za', 'ti3zen', 't1k', 't1l', 'tl5ing', 't5log', 't3ly',
        't1m', 'tme4', 't4mo', 't1n2', 'to3b', 'to5crat', 'to2gr', 'to5ic', 'to2ma',
        'tom4b', 'to3my', 'ton4ali', 'to3nat', 'to4pi', 'top4ic', 'tor5iz', 'tos2',
        't5o5taliz', 'to4v', 't1p2', 'tra3b', 'tra5ch', 'traci4', 'trac4it', 'tra4pe',
        'tra5vers', 'treat5i', 'tre5f', 'tri4a', 'trib5ut', 't4rici', 'tri4v', 't3roph',
        'tro4v', 'tr4up', 't1s2', 't3sc', 'tsh4', 't3sw', 't3t2', 't4tes', 't5to',
        'ttu4', 'tu1a', 'tu3ar', 'tu4bi', 'tud2', 'tu4gs', 'tu4l', 'tum5ul', 'tu3ni',
        'tu3s', 't2ura', 't3ureo', 'tur5is', 'tu5ry', 't1v', 't1w4', 'twis4', 't4y',
        'ty4l', 'ty5ph', 'u1a4', 'uac4', 'ua5na', 'uan4i', 'uar5ant', 'uar2d', 'uar3i',
        'uar3t', 'u1at', 'uav4', 'ub4e', 'u4bel', 'u3ber', 'u4bero', 'u1b4i', 'u4b5ing',
        'u3ble', 'u3ca', 'uci4b', 'uc4it', 'ucle3', 'u3cr', 'u3cu', 'u4cy', 'ud5d',
        'ud3er', 'ud5est', 'udev4', 'u1dic', 'ud3ied', 'ud3ies', 'ud5is', 'u5dit',
        'u4don', 'ud4si', 'u4du', 'u4ene', 'uens4', 'uen4te', 'uer4il', 'u3fe', 'ug5in',
        'u3ing', 'uir4m', 'uita4', 'uiv3', 'uiv4er', 'u5j', 'u1la', 'ula5b', 'u5lati',
        'ulch4', 'ul3der', 'ul4e', 'u1len', 'ul4gi', 'ul2i', 'u5lia', 'ul3ing', 'ul5ish',
        'ul4lar', 'ul4li4b', 'ul4lis', 'ul4mo', 'ul4ti5m', 'ul1tr', 'u1lu', 'ul5ul',
        'ul5v', 'um5ab', 'um4bi', 'um4bly', 'u1mi', 'u4m3ing', 'umor5o', 'um2p',
        'unat4', 'u2ne', 'un4er', 'u1ni', 'un4im', 'u2nin', 'un5ish', 'uni3v', 'un3s4',
        'un4sw', 'unt3ab', 'un4ter', 'un4tes', 'unu4', 'un5y', 'u4o', 'uo3y', 'up3as',
        'u5pe', 'up3in', 'u3pl', 'up3p', 'upport5', 'upt5ib', 'uptu4', 'u1ra', 'ur4al',
        'u4rasi', 'ur4be', 'urc4', 'ur1d', 'ure5at', 'ur4fer', 'ur4fr', 'u3rif',
        'uri4fic', 'ur1in', 'u3rio', 'u1rit', 'ur3iz', 'ur2l', 'url5ing', 'ur4no',
        'uros4', 'ur4pe', 'ur4pi', 'urs5er', 'ur5tes', 'ur3the', 'urti4', 'ur4tie',
        'u3ru', 'us4ad', 'us4an', 'us4ap', 'usc2', 'us3ci', 'use5a', 'u5sia', 'us4lin',
        'us1p', 'us5sl', 'us5tere', 'us1tr', 'u2su', 'usur4', 'uta4b', 'u3tat', 'u4te',
        'u4tel', 'u4ten', 'uten4i', 'u1t2i', 'uti5liz', 'u3tine', 'ut3ing', 'ution5a',
        'u4tis', 'u5tiz', 'u4t1l', 'ut5of', 'uto5g', 'uto5matic', 'u5ton', 'u4tou',
        'uts4', 'u3u', 'uu4m', 'u1v2', 'uxu3', 'uz4e', 'v1a', 'v5ativ', 'v5ely',
        'ven3om', 'v5enue', 'v4erd', 'v5ere', 'v4erel', 'v3eren', 'ver5enc', 'v4eres',
        'ver3ie', 'vermi4n', 'v3ert', 'v4est', 'v5ic4', 'vi5cel', 'v4ida', 'vid3i',
        'v3if', 'vi5gn', 'vil3iz', 'v1in', 'vin5d', 'v5ing', 'vi1o', 'vi4p', 'vi5ro',
        'vis3it', 'vi3so', 'vi3su', 'v1it', 'v5ity', 'v3o4l', 'vo4ca', 'vom5i', 'v3ory',
        'vo4ta', 'v4oy', 'v3ues', 'v4ul', 'w5abl', 'wa5ger', 'wag5o', 'wait5', 'w5al',
        'wam4', 'war4t', 'was4t', 'wa1te', 'wa5ver', 'w1b', 'wea5rie', 'weath3',
        'wed4n', 'weet3', 'wee5v', 'wel4l', 'w1er', 'west3', 'w3ev', 'whi4', 'wi2',
        'wil2', 'will5in', 'win4de', 'win4g', 'wir4', 'w5is4', 'wiz5', 'w4k', 'wl4es',
        'wl3in', 'w4no', 'w5p', 'wra4', 'wri4', 'writa4', 'w3sh', 'ws4l', 'ws4pe',
        'w5s4t', 'w1t', 'w1y', 'x1a', 'xac5e', 'x4ago', 'xam3', 'x4ap', 'xas5', 'x3c2',
        'x1e', 'xe4cuto', 'x2ed', 'xer4i', 'xe5ro', 'x1h', 'xhi2', 'xhil5', 'xhu4',
        'x3i', 'xi5a', 'xi5c', 'xi5di', 'x4ime', 'xi5miz', 'x3o', 'x4ob', 'x3p',
        'xpan4d', 'xpecto5', 'xpe3d', 'x1t2', 'x3ti', 'x1u', 'xu3a', 'xx4', 'y5ac',
        'ya4d', 'y5ai', 'y4alt', 'y2b', 'y3c', 'y2ce', 'yc5er', 'y3ch', 'ych4e',
        'ycom4', 'ycot4', 'y2d', 'y5ee', 'y4er', 'y4erf', 'yes4', 'ye4t', 'y5gi',
        'y3h', 'y1i', 'y3la', 'ylla5bl', 'y3lo', 'y5lu', 'ymbol5', 'yme4', 'ympa3',
        'yn3chr', 'yn5d', 'yn5g', 'yn5ic', 'y5nx', 'y1o4', 'yo5d', 'y4o5g', 'yom4',
        'yo5net', 'y4ons', 'y4os', 'y4ped', 'yper5', 'yp3i', 'y3po', 'y4poc', 'yp2ta',
        'y5pu', 'yra5m', 'yr5ia', 'y3ro', 'yr4r', 'ys4c', 'y3s2e', 'ys3ica', 'ys3io',
        '3ysis', 'y4so', 'yss4', 'ys1t', 'ys3ta', 'ysur4', 'y3thin', 'yt3ic', 'y1w',
        'z2a', 'z5a2b', 'zar2', 'z4b', 'z4e', 'ze4n', 'ze4p', 'z1er', 'ze3ro', 'z2i',
        'z4il', 'z4is', 'z5is', 'z2z', 'zz3w',
    ];

    /** @var array<string,int[]>|null pattern text => score at each position */
    private static ?array $compiled = null;

    private static function compile(): array
    {
        if (self::$compiled !== null) {
            return self::$compiled;
        }

        $out = [];

        foreach (self::PATTERNS as $pattern) {
            $letters = '';
            $scores = [];
            $len = strlen($pattern);

            for ($i = 0; $i < $len; $i++) {
                $c = $pattern[$i];

                if ($c >= '0' && $c <= '9') {
                    $scores[strlen($letters)] = (int) $c;
                } else {
                    $letters .= $c;
                }
            }

            $out[$letters] = $scores;
        }
        return self::$compiled = $out;
    }

    /**
     * Split a word at its hyphenation points.
     *
     * Returns the pieces in order; joining them reproduces the word. A word
     * with no legal break comes back as a single piece.
     *
     * @return string[]
     */
    public static function split(string $word, bool $auto = true): array
    {
        // An author-supplied soft hyphen always wins over the algorithm
        if (str_contains($word, self::SOFT_HYPHEN)) {
            return array_values(array_filter(explode(self::SOFT_HYPHEN, $word), 'strlen'));
        }

        if (!$auto) {
            return [$word];
        }

        $lower = strtolower($word);

        // Only plain ASCII words are attempted; anything else stays whole
        if (!preg_match('/^[a-z]+$/', $lower) || strlen($lower) < self::LEFT_MIN + self::RIGHT_MIN + 1) {

            return [$word];
        }

        $patterns = self::compile();
        $target = '.' . $lower . '.';
        $length = strlen($target);
        $points = array_fill(0, $length + 1, 0);

        for ($i = 0; $i < $length; $i++) {
            for ($len = 1; $len <= $length - $i; $len++) {
                $slice = substr($target, $i, $len);

                if (!isset($patterns[$slice])) {
                    continue;
                }

                foreach ($patterns[$slice] as $offset => $score) {
                    $at = $i + $offset;

                    if ($score > ($points[$at] ?? 0)) {
                        $points[$at] = $score;
                    }
                }
            }
        }

        // Odd scores are break points. Index 0 is the leading '.'
        $pieces = [];
        $current = '';
        $wordLength = strlen($word);

        for ($i = 0; $i < $wordLength; $i++) {
            $current .= $word[$i];
            $at = $i + 2;   // account for the leading '.' and 1-based offset
            $left = $i + 1;
            $right = $wordLength - $left;

            if ($left >= self::LEFT_MIN && $right >= self::RIGHT_MIN
                && (($points[$at] ?? 0) % 2 === 1)) {
                $pieces[] = $current;
                $current = '';
            }
        }

        if ($current !== '') {
            $pieces[] = $current;
        }

        return $pieces === [] ? [$word] : $pieces;
    }
}
