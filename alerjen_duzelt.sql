USE yemekhane;
UPDATE yemekler SET alerjen = NULL;

UPDATE yemekler SET alerjen = 'gluten' WHERE id IN (1,2,3,4,5,6,7,8,21,22,23,24,25,26,37,38,39,42,49);
UPDATE yemekler SET alerjen = 'sut' WHERE id IN (4,25,26,27,32,36,37,39,42,44,45,47,50);
UPDATE yemekler SET alerjen = 'yumurta' WHERE id IN (26,33,39,43,48);

UPDATE yemekler SET alerjen = 'sut,gluten' WHERE id IN (25,37,42);
UPDATE yemekler SET alerjen = 'sut,yumurta,gluten' WHERE id IN (26,39);