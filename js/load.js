document.getElementById('button2').style.visibility = 'hidden';
document.getElementById('button1').style.visibility = 'hidden';

document.getElementById('button3').onclick = function() {
    document.getElementById('winner3').style.visibility = 'visible';
    document.getElementById('button2').style.visibility = 'visible';
    
}
document.getElementById('button2').onclick = function() {
    document.getElementById('winner2').style.visibility = 'visible';
    document.getElementById('button1').style.visibility = 'visible';
}
document.getElementById('button1').onclick = function() {
    document.getElementById('winner2').style.visibility = 'visible';
}