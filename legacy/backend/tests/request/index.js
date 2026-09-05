//VARIABLE GLOBAL 
let formCategoria = document.forms['formCategoria'];

function load(){
    //Usamos POST de forma provisional por que el HTACCSS no llega a /tests
    data = {
        id: document.getElementById("datoId").value
    }
    console.log("Guardando datos...");
    fetch("http://localhost/lab_prog_2025_reales_ivan/tests/request/requestLoadTest.php",{
        method: "post",
        headers: {"Content-Type": "application/json", "Accept": "application/json"},
        body: JSON.stringify(data)
    })
    .then(response => {
        if(!response.ok){
            throw new Error(response.status);
        }
        return response.json();
    })
    .then(response => {
        console.log(response);
        //ASI DEBERIA CARGARLO AL FORMULARIO PARA DESPUES EDITARLO USANDO LOAD.
        formCategoria.datoNombre.value = response.result.nombre;
    })
    .catch(error => {
        console.error("Error de petición", error);
    })
}

function save(e){
    e.preventDefault();
    //CAMPOS DEL FORMULARIO
    data = {
        nombre: formCategoria.datoNombre.value
    }
    console.log("Guardando datos...");
    fetch("http://localhost/lab_prog_2025_reales_ivan/tests/request/requestTest.php",{
        method: "post",
        headers: {"Content-Type": "application/json", "Accept": "application/json"},
        body: JSON.stringify(data)
    })
    .then(response => {
        if(!response.ok){
            throw new Error(response.status);
        }
        return response.json();
    })
    .then(response => {
        console.log(response);
    })
    .catch(error => {
        console.error("Error de petición", error);
    })
    //SI TIRA ERROR EL PROBADOR ESTA BIEN PORQUE NO TENEMOS UN RESPONSE TODAVIA
    // EN EL PREVIEW TIENE QUE HABER UN ARRAY VACIO YA QUE EL PRINTR DEL POST YA NO POSTEA POR AHI
    //ES ASINCRONO
}